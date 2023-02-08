<?php

namespace LimeAPI;

const WL = 'whitelist';
const BL = 'blacklist';

const ALLOW = 'allowed';
const BLOCK = 'blocked';

const IS_RE = 'IsPattern';

function deprecated($old, $new)
{
  error_log("The use of the '$old' option is deprecated; use '$new' instead.");
}

class AccessList
{
  private ?array $blocked = null;
  private ?array $allowed = null;

  private bool $allowedIsPattern = false;
  private bool $blockedIsPattern = false;

  public function __construct(array $opts)
  {
    if (isset($opts[WL]))
    {
      deprecated(WL, ALLOW);
      if (!isset($opts[ALLOW]))
      {
        $opts[ALLOW] = $opts[WL];
      }
    }

    if (isset($opts[BL]))
    {
      deprecated(BL, BLOCK);
      if (!isset($opts[BLOCK]))
      {
        $opts[BLOCK] = $opts[BL];
      }
    }

    $this->setFromOpts(ALLOW, $opts);
    $this->setFromOpts(BLOCK, $opts);
  }

  // Used by the constructor.
  private function setFromOpts(string $prop, array $opts)
  {
    $reProp = $prop.IS_RE;

    if (isset($opts[$prop]))
    {
      $value = $opts[$prop];

      if (isset($opts[$reProp]) && is_bool($opts[$reProp]))
        $reValue = $opts[$reProp];
      else
        $reValue = null;

      if (is_array($value))
      {
        $this->$prop = $value;
        if (!isset($reValue))
          $reValue = false;
      }
      elseif (is_string($value))
      {
        $this->$prop = [$value];
        if (!isset($reValue))
          $reValue = true;
      }

      $this->$reProp = $reValue;
    }
  }

  private function inList(string $value, string $prop, bool $default)
  {
    if (isset($this->$prop))
    { // The list was found, so let's check for the value.
      $list = $this->$prop;
      $reProp = $prop.IS_RE;
      if ($this->$reProp)
      { // One or more regex patterns to match.
        foreach ($list as $pattern)
        {
          if (preg_match($pattern, $value))
          { // Only one pattern has to match.
            return true;
          }
        }
      }
      elseif (in_array($value, $list))
      { // String literal found in the list.
        return true;
      }
      // Was not in the list.
      return false;
    }
    else 
    { // List is undefined, use the specified default.
      return $default;
    }
  }

  public function ok(string $value)
  {
    if (!$this->inList($value, ALLOW, true )) return false;
    if ( $this->inList($value, BLOCK, false)) return false;
    // If we reached here, all is good.
    return true;
  }

}
