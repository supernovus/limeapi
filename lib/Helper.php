<?php

namespace LimeAPI;

use LimeAPI\Helper\Questions;

/**
 * An all-in-one wrapper around the DB and RC libraries.
 */
class Helper
{
  use \Lum\Meta\Cache;

  protected $_session_cache_key = 'limeapi_cache';

  protected $wsapi;
  protected $dbapi;
  
  public function __construct (array $opts=[])
  {
    $dbopts = isset($opts['dbopts']) ? $opts['dbopts'] : $opts;
    $this->dbapi = new DB($dbopts);

    $wsopts = isset($opts['wsopts']) ? $opts['wsopts'] : $opts;
    $this->wsapi = new RC($wsopts);
  }

  public function enableCache ($clear=false)
  {
    if ($clear)
    {
      $this->clear_cache(false);
    }
    else
    {
      $this->load_cache($this->_session_cache_key);
    }
  }

  public function disableCache ()
  {
    $this->clear_cache(true); 
  }

  public function saveCache ()
  {
    $this->save_cache($this->_session_cache_key);
  }

  public function getWS ()
  {
    return $this->wsapi;
  }

  public function getDB ()
  {
    return $this->dbapi;
  }
  
  public function get_survey_title ($sid)
  {
    $cached = $this->cache([$sid, 't']);
    if (isset($cached))
    {
      return $cached; 
    }
    else
    {
      $slang = $this->wsapi->get_language_properties($sid, ['surveyls_title']);
      if (is_array($slang) && isset($slang['surveyls_title']))
      {
        $stitle = $slang['surveyls_title'];
      }
      else
      {
        $stitle = null;
      }
      $this->cache([$sid, 't'], $stitle);
      return $stitle;
    }
  }

  public function get_questions ($sid, $opts=[])
  {
    $cached = $this->cache([$sid, 'q']);
    if (isset($cached))
    {
      return $cached;
    }
    else
    {
      $questions = $this->dbapi->get_questions($sid, $opts);
      $this->cache([$sid, 'q'], $questions);
      return $questions;
    }
  }

  public function getQuestions(mixed $qspec, array $opts=[]): Questions
  {
    return new Questions($this, $qspec, $opts);
  }

  public function getQuestion ($qspec, $qpath)
  {
    if (is_numeric($qspec))
    { // It's an sid instead of a questions array.
      $qspec = $this->get_questions($qspec);
    }

    if (is_array($qspec))
    { // We have our list of questions.
      $subquery = null;
      if (is_array($qpath))
      { // We're digging down through nested questions.
        $subquery = $qpath;
        $qpath = array_shift($subquery);
        if (count($subquery) == 1)
        { // Last item, make it a scalar.
          $subquery = $subquery[0];
        }
      }

      foreach ($qspec as $question)
      {
        if ($question['title'] == $qpath)
        {
          if (isset($subquery))
          { // We need to dig deeper.
            if (isset($question['subquestions']))
            {
              $subquestions = $question['subquestions'];
              return $this->getQuestion($subquestions, $subquery);
            }
          }
          else
          { // No subquery, let's return.
            return $question;
          }
        }
      }
    }
  }

  public function getAnswer ($question, $code, $qspec=null)
  {
    if (is_string($question))
    { // Question name passed instead of a question array.
      if (isset($qspec))
      { // Get the question using the qspec.
        $question = $this->getQuestion($qspec, $question);
      }
      else
      { // Cannot continue.
        return;
      }
    }
    
    if (is_array($question) && isset($question['answers']))
    {
      foreach ($question['answers'] as $answer)
      {
        if ($answer['code'] == $code)
        {
          return $answer;
        }
      }
    }
  }

  public function get_timings ($sid, $opts=[])
  {
    $r = isset($opts['rid']) ? $opts['rid'] : '*';
    $c = isset($opts['column']) ? $opts['column'] : '*';
    $cached = $this->cache([$sid, 'z', $r, $c]);
    if (isset($cached))
    {
      return $cached;
    }
    else
    {
      $timings = $this->dbapi->get_timings($sid, $opts);
      $this->cache([$sid, 'z', $r, $c], $timings);
      return $timings;
    }
  }

  public function export_responses_csv ($sid, $opts=[])
  {
    $c = isset($opts['ctype']) ? $opts['ctype'] : 'all';
    $cached = $this->cache([$sid, 'r', $c]);
    if (isset($cached))
    {
      return $cached;
    }
    else
    {
      $responses = $this->wsapi->export_responses_csv($sid, $opts);
      $this->cache([$sid, 'r', $c], $responses);
      return $responses;
    }
  }

  public function get_question_responses ($csvdata, $opts=[])
  {
    if (is_numeric($csvdata))
    { // The sid was passed instead of output from export_responses_csv()
      $csvdata = $this->export_responses_csv($csvdata, $opts);
      if (!isset($csvdata))
      {
        return;
      }
    }

    return $this->wsapi->get_question_responses($csvdata, $opts);
  }

  public function __call ($method, $args)
  {
    if (is_callable([$this->dbapi, $method]))
    {
      return call_user_func_array([$this->dbapi, $method], $args);
    }
    else
    {
      return call_user_func_array([$this->wsapi, $method], $args);
    }
  }
}
