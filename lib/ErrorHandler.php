<?php

namespace LimeAPI;

trait ErrorHandler
{
  protected $_error_handler;

  public function handle_error ($error, $fatal=true)
  { 
    if (isset($this->_error_handler) && is_callable($this->_error_handler))
    { // Use a custom error handler.
      return call_user_func($this->_error_handler, $error, $fatal);
    }

    if (is_object($error) && $error instanceof \Exception && $fatal)
    { // A shortcut without any further processing.
      throw $error;
    }

    if (is_string($error))
    { // It's already a string.
      $msg = $error;
    }
    elseif (is_array($error) || is_object($error))
    { // Encode as a JSON string.
      $flags = property_exists($this, 'error_json_flags')
        ? $this->error_json_flags 
        : 0;
      $msg = json_encode($error, $flags);
    }
    else
    { // No supported error format found.
      $msg = 'an unknown error occurred';
    }

    if (property_exists($this, 'error_template'))
    { // Use a template for the error message.
      $tmpl = $this->error_template;
      $e = '{error}';
      if (str_contains($tmpl, $e))
      {
        $msg = str_replace($e, $msg, $tmpl);
      }
      else
      {
        $msg = "$tmpl $msg";
      }
    }

    if ($fatal)
    {
      throw new \Exception($msg);
    }
    else
    {
      error_log($msg);
    }

  } // handle_error()

  public function set_error_handler($h, $fatal=true)
  {
    $he = 'handle_error';

    if ($h === $this || $h === $he 
      || (is_array($h) && isset($h[0],$h[1]) 
      && $h[0] === $this && $h[1] === $he))
    { // Circumstances that would do very bad things.
      throw new \Exception("infinite recursion is not welcome here");
    }

    if (is_callable($h))
    { // It was a handler itself.
      $this->_error_handler = $h;
    }
    elseif (is_object($h) && is_callable([$h, $he]))
    { // An object, let's see if it has a 'handle_error' method.
      $this->_error_handler = [$h, $he];
    }
    elseif (is_string($h) && method_exists($this, $h))
    { // It's a method call.
      $this->_error_handler = [$this, $h];
    }
    elseif (is_null($h))
    { // Setting it to null means unset.
      unset($this->_error_handler);
    }
    else
    { // Report an error assigning the error handler.
      return $this->handle_error(["unsupported_error_handler"=>$h], $fatal);
    }
  }

}