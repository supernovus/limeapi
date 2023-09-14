<?php

namespace LimeAPI\Helper;

trait HasData
{
  abstract protected function dataProperty(): string;

  // Property accessor interface.

  public function __isset($key): bool
  {
    $prop = $this->dataProperty();
    return isset($this->$prop[$key]);
  }

  public function __get($key): mixed
  {
    $prop = $this->dataProperty();
    return $this->$prop[$key] ?? null;
  }

  public function __set($key, $value): void
  {
    throw new ReadOnlyException($key);
  }

  public function __unset($key): void
  {
    throw new ReadOnlyException($key);
  }

  // ArrayAccess interface.

  public function offsetExists($key): bool
  {
    return $this->__isset($key);
  }

  public function offsetGet($key): mixed
  {
    return $this->__get($key);
  }

  public function offsetSet($key, $value): void
  {
    $this->__set($key, $value);
  }

  public function offsetUnset($key): void
  {
    $this->__unset($key);
  }

  // JsonSerializable interface

  public function jsonSerialize(): mixed
  {
    $prop = $this->dataProperty();
    return $this->$prop;
  }
}