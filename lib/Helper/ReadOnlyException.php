<?php

namespace LimeAPI\Helper;

class ReadOnlyException extends \Exception 
{
  public function __construct(
    string $name="",
    int $code=0,
    ?\Throwable $prev=null
  )
  {
    $msg = "Property '$name' is read-only";
    parent::__construct($msg, $code, $prev);
  }
}
