<?php

namespace LimeAPI;

/**
 * A class to work with Limesurvey's RemoteControl 2 API.
 *
 * Only works with the JSON-RPC version of the API.
 */
class RC
{
  use ErrorHandler;

  public $client;

  private $session_key;
  private $apiuser;
  private $apipass;

  protected $error_template = 'LimeAPI\RC error: ';

  const VALID_METHODS =
  [
    'activate_survey', 'activate_tokens', 'add_group', 'add_language',
    'add_participants', 'add_response', 'add_survey', 'copy_survey',
    'cpd_importParticipants', 'delete_group', 'delete_language',
    'delete_participants', 'delete_question', 'delete_survey',
    'export_responses', 'export_responses_by_token', 'export_statistics',
    'export_timeline', 'get_group_properties', 'get_language_properties',
    'get_participant_properties', 'get_question_properties',
    'get_response_ids', 'get_site_settings', 'get_summary',
    'get_survey_properties', 'get_uploaded_files', 'import_group',
    'import_question', 'import_survey', 'invite_participants',
    'list_groups', 'list_participants', 'list_questions', 'list_surveys',
    'list_users', 'mail_registered_participants', 'remind_participants',
    'set_group_properties', 'set_language_properties', 
    'set_participant_properties', 'set_question_properties',
    'set_quota_properties', 'update_response', 'upload_file',
  ];

  /**
   * Build a RCAPI object.
   */
  public function __construct ($opts)
  {
    if (isset($opts['apiuser']))
    {
      $this->apiuser = $opts['apiuser'];
      unset($opts['apiuser']);
    }

    if (isset($opts['apipass']))
    {
      $this->apipass = $opts['apipass'];
      unset($opts['apipass']);
    }

    if (isset($opts['error_handler']))
    {
      $this->set_error_handler($opts['error_handler']);
      unset($opts['error_handler']);
    }

    $this->client  = new \Lum\JSON\RPC\Client($opts);
  }

  public function __destruct ()
  {
    $this->release_session_key();
  }

  /**
   * Get a session key, used for the rest of the calls.
   *
   * If an 'apiuser' and 'apipass' parameters are specified in the constructor
   * options, then this will be called implicitly before the first API call.
   */
  public function get_session_key ($user, $pass)
  {
    if (isset($this->session_key))
    {
      $this->release_session_key();
    }
    
    $res = $this->client->get_session_key($this->apiuser, $this->apipass);
    if ($res->success && is_string($res->result))
    {
      return $this->session_key = $res->result;
    }
    else
    {
      return $this->handle_error(['get_session_key'=>$res]);
    }
  }

  /**
   * End a session by releasing the session key.
   */
  public function release_session_key ()
  {
    if (isset($this->session_key))
    {
      $this->client->release_session_key($this->session_key);
      unset($this->session_key);
    }
  }

  /**
   * Get responses as parsed CSV data.
   */
  public function export_responses_csv ($sid, $opts=[], $delim=null)
  {
#    error_log("export_responses_csv($sid,".json_encode($opts).')');
    $opt_defaults = 
    [
      'ctype' => 'all',
      'htype' => 'code',
      'rtype' => 'short',
      'lang'  => null,
      'from'  => null,
      'to'    => null,
    ];
    foreach ($opt_defaults as $optname => $optval)
    {
      if (!isset($opts[$optname]))
      {
        $opts[$optname] = $optval;
      }
    }
    if (isset($opts['token']))
    {
      $tid = $opts['token'];
      $responses = $this->export_responses_by_token($sid, 'csv', $tid,
        $opts['lang'], $opts['ctype'], $opts['htype'], $opts['rtype']);
    }
    else
    {
      $responses = $this->export_responses($sid, 'csv', $opts['lang'],
        $opts['ctype'], $opts['htype'], $opts['rtype'], $opts['from'], 
        $opts['to']);
    }
#    error_log("export_responses_csv: ".json_encode($responses));
    if (is_string($responses))
    {
#      error_log("responses are a string, decoding");
      $bom = pack('CCC', 0xEF, 0xBB, 0xBF); // Strip the BOM.
      $responses = ltrim(base64_decode($responses), $bom);
      if (is_null($delim))
      { // Attempt to auto-detect the delimiter.
        // This only works if the first four characters are '"id"'.
        if (substr($responses, 0, 4) == '"id"')
        {
          $delim = substr($responses, 4, 1);
        }
        else
        { // Fatal error cannot continue.
          return $this->handle_error(['export_responses'=>'could not detect delimiter']);
        }
      }

      $csvparse = new \ParseCsv\Csv();
      $csvparse->delimiter = $delim;
      $csvparse->parse($responses);
      $responses = $csvparse->data;
      #error_log("CSV returned: ".json_encode($responses));
      if (isset($responses) && count($responses) > 0)
      {
        #if (isset($responses[0]))
        #  error_log("CSV has keys: ".json_encode(array_keys($responses[0])));

        // If the last entry has a blank 'id', nuke it.
        $li = array_key_last($responses);
        $lv = $responses[$li];
        if (!isset($lv['id']) || $lv['id'] == '')
        {
          array_pop($responses);
        }
      }
      return $responses;
    }
    elseif (!isset($opts['fatal']) || $opts['fatal'])
    {
      return $this->handle_error(['export_responses'=>$responses]);
    }
    elseif (isset($opts['return_error']) && $opts['return_error'])
    {
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

    $ignore_blanks = isset($opts['ignore_blanks']) 
      ? $opts['ignore_blanks']
      : true;

    $access = new AccessList($opts);

    $qdata = [];
    foreach ($csvdata as $record)
    {
      foreach ($record as $qname => $qval)
      {
        $qcol = explode('[', $qname)[0];

        if ($ignore_blanks && trim($qval) == '') continue; // skip blanks.

        if (!$access->ok($qcol)) continue; // Access list says no.
  
        if (isset($qdata[$qname]))
        {
          $qdata[$qname]['count']++;
          if (isset($qdata[$qname]['vals'][$qval]))
          {
            $qdata[$qname]['vals'][$qval]['count']++;
          }
          else
          {
            $qdata[$qname]['vals'][$qval] = 
            [
              'count'   => 1,
              'percent' => 0,
            ];
          }
        }
        else
        {
          $qdata[$qname] = 
          [
            'count' => 1,
            'vals'  => 
            [
              $qval =>
              [
                'count'   => 1,
                'percent' => 0,
              ],
            ],
          ];
        }
      }
    }
    foreach ($qdata as $qname => &$qdef)
    {
      $total = $qdef['count'];
      foreach ($qdef['vals'] as $qval => &$qvdef)
      {
        $percent = round(($qvdef['count'] / $total) * 100, 2);
        $qvdef['percent'] = $percent;
      }
    }
   
    if (isset($opts['sort']) && $opts['sort'])
    {
      $qkeys  = array_keys($qdata);
      natcasesort($qkeys);
      $newqdata = [];
      foreach ($qkeys as $qk)
        $newqdata[$qk] = $qdata[$qk];
      $qdata = $newqdata;
    }

    return $qdata;
  }

  /**
   * Get token attributes/properties.
   *
   * @param int $sid  Survey ID.
   * @param (str|array) $attrs  Attribute(s) to return.
   * @param array $opts  Named options:
   *
   *  'single' => (bool)    Return the single attribute explicitly.
   *                        Only works in two cases: 1.) an 'id' or 'token' was
   *                        specified, in which case we return the attribute
   *                        value directly. 2.) The 'map' option was true, in
   *                        which case for each token, we map the attribute
   *                        value instead of the array of all values.
   *                        If this is true, only a single value must be passed
   *                        as the $attrs parameter.
   *
   *  'id' => (int)         Get attrs for the specified respondent id.
   *  'token' => (str)      Get attrs for the specified token string.
   *                        If either of the above are used, the rest of the
   *                        options below are ignored.
   *
   *  'start' => (int)      The offset of participants to start listing from.
   *                        Default value: 0
   *  'limit' => (int)      The maximum number of results to return.
   *                        Default value: 999999
   *  'unused' => (bool)    Include unused tokens.
   *  'match' => (array)    Optional query for matching tokens by properties.
   *
   *  'map' => (bool)       If true, the results will be mapped by token string.
   *  'uppercase' => (bool) If true, the token string will be uppercased.
   *
   * @return mixed  The results differ depending on the options passed.
   */
  public function get_token_attrs ($sid, $attrs, $opts=[])
  {
    if (is_string($attrs))
    { // A single attribute, we still need to wrap it in an array.
      $attrs = [$attrs];
    }

    if (isset($opts['single']) && $opts['single'])
    {
      if (is_array($attrs) && count($attrs) == 1)
      {
        $singleValue = true;
      }
      else
      {
        error_log("Use of 'single' option with multiple attrs specified.");
        return null;
      }
    }
    else
    {
      $singleValue = false;
    }

    if (isset($opts['id']) && is_numeric($opts['id']))
    {
      $tokenQuery = $opts['id'];
    }
    elseif (isset($opts['token']) && is_string($opts['token']))
    {
      $tokenQuery = ['token'=>$opts['token']];
    }
    else
    {
      $tokenQuery = null;
    }

    if (isset($tokenQuery))
    {
      $results = $this->get_participant_properties($sid, $tokenQuery, $attrs);
      if ($singleValue)
      {
        if (isset($results, $results[$attrs[0]]))
        {
          return $results[$attrs[0]];
        }
        else
        {
          error_log("Missing token attribute '{$attrs[0]}' in response: ".json_encode($results));
          return null;
        }
      }
      return $results;
    }

    $start  = isset($opts['start'])  ? $opts['start']   : 0;
    $limit  = isset($opts['limit'])  ? $opts['limit']   : 999999;
    $unused = isset($opts['unused']) ? $opts['unusued'] : false;
    $match  = isset($opts['match'])  ? $opts['match']   : null;
    $map    = isset($opts['map'])    ? $opts['map']     : false;

    $results = $this->list_participants($sid, $start, $limit, $unused, $attrs, $match);

    if (!$map)
    {
      return $results;
    }

    $upperCase = isset($opts['uppercase']) ? $opts['uppercase'] : false;

    $mapped = [];
    foreach ($results as $res)
    {
      $tstr = $res['token'];
      if ($upperCase)
      {
        $tstr = strtoupper($tstr);
      }
      if ($singleValue)
      {
        $value = $res[$attrs[0]];
        $mapped[$tstr] = $value;
      }
      else
      {
        $mapped[$tstr] = $res;
      }
    }
    return $mapped;
  }
  
  /**
   * All other methods are assumed to be API calls that have the session key
   * as the first parameter.
   *
   * Call them as you normally would, but leave off the session key as it
   * will be added automatically.
   */
  public function __call ($method, $args)
  {
    if (!in_array($method, self::VALID_METHODS))
    { // Invalid method, we can't continue.
      return $this->handle_error(
      [
        "code"   => "invalid_method", 
        "msg"    => "An invalid API call was made",
        "method" => $method,
      ]);
    }

    if (!isset($this->session_key))
    {
      if (isset($this->apiuser, $this->apipass))
      {
        $this->get_session_key($this->apiuser, $this->apipass);
      }
      else
      {
        return $this->handle_error(
        [
          "code" => "no_auth", 
          "msg"  => "No API user or pass was specified."
        ]);
      }
    }
   
    array_unshift($args, $this->session_key);
    $response = call_user_func_array([$this->client, $method], $args);
    if ($response->success && isset($response->result))
    {
      return $response->result;
    }
    else
    {
      return $this->handle_error(
      [
        "code"     => "api_error",
        "msg"      => "The API returned a non-successful response.",
        "method"   => $method,
        "response" => $response,
      ]);
    }
  }
}

