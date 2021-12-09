<?php

namespace LimeAPI;

use \Lum\DB\PDO\Simple;

class DB
{
  use ErrorHandler;

  protected $db;

  protected $debug = false;

  protected $table_surveys   = 'surveys';
  protected $table_questions = 'questions';
  protected $table_answers   = 'answers';
  protected $table_labels    = 'labels';
  protected $table_lsets     = 'labelsets';
  protected $table_groups    = 'groups';
  protected $table_qattrs    = 'question_attributes';

  protected $table_timing_prefix = 'survey_';
  protected $table_timing_suffix = '_timings';

  protected $error_template = 'LimeAPI\DB error: ';

  /**
   * Build a new LimeAPI\DB object.
   *
   * @param array $opts Options for building the object.
   *
   * One of the following is mandatory:
   *
   *  "db" (Lum\DB\PDO\Simple)   A database instance already initialized.
   *
   *  "dbconf" (string|array)    Database connection configuration.
   *
   *    May be a JSON string, the pathname to a JSON configuration file,
   *    or an already decoded configuration file as a PHP array.
   *    See \Lum\DB\PDO\Simple::__construct() for details.
   *
   * Options to override the defaults for the names of Limesurvey tables:
   *
   *  "table_prefix"    (string)  Prefix to add to ALL tables.  ['']
   *
   *  "surveys_table"   (string)  Surveys.      ['surveys']
   *  "questions_table" (string)  Questions.    ['questions']
   *  "answers_table"   (string)  Answers.      ['answers']
   *
   *  "timing_prefix"   (string)  Timing table prefix.  ['survey_']
   *  "timing_suffix"   (string)  Timing table suffix.  ['_timings']
   *
   * The following Limesurvey tables are reserved for future use:
   *
   *  "labels_table"    (string)  Labels.          ['labels']
   *  "lsets_table"     (string)  Label Sets.      ['labelsets']
   *  "groups_table"    (string)  Groups.          ['groups']
   *  "qattrs_table"    (string)  Question Attrs.  ['question_attributes']
   *
   */
  public function __construct ($opts)
  {
    if (isset($opts['db']) && $opts['db'] instanceof Simple)
    { 
      $this->db = $opts['db'];
    }
    elseif (isset($opts['dbconf']))
    {
      $this->db = new Simple($opts['dbconf']);
    }
    else
    {
      throw new \Exception("DB needs one of 'db' or 'dbconf' parameters.");
    }

    if (isset($opts['error_handler']))
    {
      $this->set_error_handler($opts['error_handler']);
    }

    if (isset($opts['table_prefix']) && is_string($opts['table_prefix']))
    {
      $prefix = $opts['table_prefix'];
    }
    else
    {
      $prefix = '';
    }

    $tables = 
    [
      'surveys','questions','answers','labels','lsets','groups','qattrs',
    ];
    foreach ($tables as $tid)
    {
      $topt  = $tid.'_table';
      $tprop = 'table_'.$tid;
      if (isset($opts[$topt]) && is_string($opts[$topt]))
      {
        $this->$tprop = $prefix.$opts[$topt];
      }
    }

    if (isset($opts['timing_prefix']))
    {
      $this->table_timing_prefix = $prefix.$opts['timing_prefix'];
    }
    if (isset($opts['timing_suffix']))
    {
      $this->table_timing_suffix = $opts['timing_suffix'];
    }
 
    if (isset($opts['debug']))
    {
      $this->debug = $opts['debug'];
    }
  }

  /**
   * Return the DB object connected to the Limesurvey database.
   *
   * @return Lum\DB\PDO\Simple
   */
  public function getDB()
  {
    return $this->db;
  }

  /**
   * Get the question definitions from the database.
   *
   * @param string|int $sid   The survey id to get the questions for.
   * @param array      $opts  Options to filter the returned questions.
   *
   *  "allowed"   (array|string)  The table names that we want to include.
   *
   *    May be an array of exact table names, or a string which will be
   *    compiled into a regular expression to match the table names.
   *
   *  "blocked"   (array|string)  The table names that we want to exclude.
   *
   *    Supports the same values as the allowed list.
   *
   * @return array  An array of question definitions (associative arrays).
   *
   *   In addition to the regular question columns, there may be an "answers"
   *   property containing an array of answers rows that were children of the
   *   question. There may also be a "subquestions" property which will be
   *   an array of questions that are children of the main question.
   */
  public function get_questions ($sid, $opts=[])
  {
    // Get the top level questions.
    $select =
    [
      'where' => ['sid'=>$sid, 'parent_qid'=>0],
    ];
    $table = $this->table_questions;
    $questions = $this->db->select($table, $select)->fetchAll();

    // If there's an allowed list, filter the questions now.
    if (isset($opts['allowed']))
    {
      $allowed = $opts['allowed'];
      if (is_array($allowed))
      {
        $questions = array_filter($questions, function ($question) 
          use ($allowed)
        {
          if (in_array($question['title'], $allowed)) return true;
        });
      }
      elseif (is_string($allowed))
      {
        $questions = array_filter($questions, function ($question)
          use ($allowed)
        {
          if (preg_match($allowed, $question['title'])) return true;
        });
      }
    }

    // The same goes for a blocked list.
    if (isset($opts['blocked']))
    {
      $blocked = $opts['blocked'];
      if (is_array($blocked))
      {
        $questions = array_filter($questions, function ($question) 
          use ($blocked)
        {
          if (!in_array($question['title'], $blocked)) return true;
        });
      }
      elseif (is_string($blocked))
      {
        $questions = array_filter($questions, function ($question)
          use ($blocked)
        {
          if (!preg_match($blocked, $question['title'])) return true;
        });
      }
    }

    // Now get nested questions and answers.
    $this->get_nested($sid, $questions);

    // Handle debug information.
    if (is_bool($this->debug) && $this->debug)
    {
      error_log(json_encode($questions, \JSON_PRETTY_PRINT));
    }
    elseif (is_string($this->debug))
    {
      file_put_contents($this->debug, 
          json_encode($questions, \JSON_PRETTY_PRINT));
    }

    return $questions;
  }

  // Never called directly, this is part of get_questions();
  protected function get_nested ($sid, &$questions)
  {
    // First, sort the questions using natural sorting.
    usort($questions, function ($a, $b)
    {
      return strnatcasecmp($a['title'], $b['title']);
    });

    $tq = $this->table_questions;
    $ta = $this->table_answers;

    // Now let's find any sub-questions and answer defintions.
    foreach ($questions as &$question)
    {
      $select =
      [
        'where' => ['sid'=>$sid, 'parent_qid'=>$question['qid']],
      ];

      $subquestions = $this->db->select($tq, $select)->fetchAll();
      if (count($subquestions) > 0)
      {
        $this->get_nested($sid, $subquestions);
        $question['subquestions'] = $subquestions;
      }
      $select =
      [
        'where' => ['qid'=>$question['qid']],
      ];

      $answers = $this->db->select($ta, $select)->fetchAll();
      if (count($answers) > 0)
      {
        if ($question['type'] == '1')
        {
          $sort = 'scale_id';
        }
        else
        {
          $sort = 'code';
        }
        // Sort the answers naturally.
        usort($answers, function ($a, $b) use ($sort)
        {
          return strnatcasecmp($a[$sort], $b[$sort]);
        });
        $question['answers'] = $answers;

      }
    }
  }

  /**
   * Get timing information from the database.
   *
   * @param mixed  $sid   The survey id we are getting timing from.
   * @param array  $opts  Options for the timing query:
   *
   *  'rid' => (str|int)  Only return results from the given response id.
   *                      This changes the format of the output significantly.
   *
   *  'raw' => (bool)     Return the results from select() directly.
   *                      If true, all further options are ignored.
   *
   *  'parse' => (bool)   Parse the times into [hours,mins,secs] structure.
   *                      If false, the times are floating point seconds.
   *
   *  'assoc' => (bool)   Convert the flat array into an associative array
   *                      indexed by id. Only applicable if 'rid' is not used.
   *
   *  'clean' => (bool)   Remove the 'id' column from the associative array.
   *                      Only applicable if 'assoc' is true.
   *
   *  'column' => (str)   Return just the single column value.
   *                      If used with 'assoc', the associative array values
   *                      will be the timing value (format depends on 'parse'.)
   *
   * @return mixed  The return value depends on the options set.
   */
  public function get_timings ($sid, $opts=[])
  {
    $rid        = isset($opts['rid']) ? $opts['rid'] : null;
    $parseTimes = (isset($opts['parse']) && $opts['parse']);
    $onlyCol    = isset($opts['column']) ? $opts['column'] : null;
    $nullCols   = (isset($opts['nulls']) && $opts['nulls']);

    $tp = $this->table_timing_prefix;
    $ts = $this->table_timing_suffix;

    $table = "${tp}{$sid}${ts}";
    $select = [];
    if (isset($rid))
    {
      $select['single'] = true;
      $select['where'] = ['id'=>$rid];
    }
    if ($onlyCol)
    {
      $select['cols'] = 'id,'.$onlyCol;
    }

#    error_log("select($table, ".json_encode($select).")");

    $timings = $this->db->select($table, $select);

    if ((isset($opts['raw']) && $opts['raw']))
    { // Return the results with no further actions performed.
      return $timings;
    }
    elseif (isset($rid))
    { // A single item, return it, after any additional parsing.
      if ($onlyCol)
      { // Return a single column from the row.
        if (isset($timings, $timings[$onlyCol]))
        {
          $value = $timings[$onlyCol];
          if ($parseTimes)
          {
            $value = $this->parse_time($value);
          }
          return $value;
        }
        else
        { // The specified column wasn't set!
          return null;
        }
      }
      if ($parseTimes && isset($timings))
      {
        $this->parse_row_times($timings);
      }
      return $timings;
    }
    else
    { // Multiple rows, return them as an array, after any parsing.
      $timings = $timings->fetchAll();
#      error_log("found ".count($timings)." rows");
#      error_log("row 0: ".json_encode($timings[0]));
      if ($parseTimes)
      {
        foreach ($timings as &$row)
        {
          $this->parse_row_times($row);
        }
      }
      if (isset($opts['assoc']) && $opts['assoc'])
      { // Convert the flat array into an associative array indexed by id.
        $removeId = (isset($opts['clean']) && $opts['clean']);
        $assoc = [];
        foreach ($timings as $row)
        {
          $rid = $row['id'];
          if ($onlyCol)
          {
            if (isset($row[$onlyCol]))
            { // Map the one column to it's id.
              $assoc[$rid] = $row[$onlyCol];
            }
            elseif ($nullCols)
            { // Value was missing, set an explicit null value.
              $assoc[$rid] = null;
            }
          }
          else
          { // Map the row to it's id.
            if ($removeId)
            {
              unset($row['id']);
            }
            $assoc[$rid] = $row;
          }
        }
        return $assoc;
      }
      return $timings;
    }
  }

  private function parse_row_times (&$row)
  {
    foreach ($row as $cn => &$col)
    {
      if ($cn == 'id') continue; // skip id.
      if (isset($col) && $col > 0)
      {
        $col = self::parse_time($col);
      }
      else
      {
        $col = [0,0,0];
      }
    }
  }

  public static function parse_time ($time)
  {
    $time = round($time);
    $hours = (int)($time/3600);
    $mins  = ($time/60)%60;
    $secs  = $time % 60;
    return [$hours,$mins,$secs];
  }

  /**
   * Return known token attribute descriptions.
   *
   * Only supports JSON and Serialize formats for the column data.
   * I never came across any in a format other than those, but who knows.
   *
   * @param string $sid   The survey id to get the descriptions for.
   * @param array  $opts  Reserved for future use.
   *
   * @return array|null  Attribute descriptions if any are defined.
   */
  public function getAttributeDescriptions ($sid, $opts=[])
  {
    $ad = 'attributedescriptions';
    $select =
    [
      'single' => true,
      'where' => ['sid'=>$sid],
      'cols' => $ad,
    ];
    $table = $this->table_surveys;
    $data = $this->db->select($table, $select);
    if (isset($data, $data[$ad]) && trim($data[$ad]) != '')
    { // We only support non-empty JSON, and Serialize formats.
      if (substr($data[$ad], 0, 1) == '{')
      { // JSON format detected.
        return json_decode($data[$ad], true);
      }
      elseif (substr($data[$ad], 0, 2) == 'a:')
      { // Serialize format detected.
        return unserialize($data[$ad]);
      }
    }
  }

}
