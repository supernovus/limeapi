<?php

namespace LimeAPI\Helper;

use LimeAPI\Helper;

/**
 * An extended helper for a complete set of Question definitions.
 * 
 * Uses the `Question` and `Answer` classes for all child objects.
 * 
 * You can iterate over it for the questions in defined order.
 * You can use property or array access to get questions by `qid` or `title`.
 * 
 * @package LimeAPI\Helper
 */
class Questions 
  implements 
    \ArrayAccess, 
    \Countable, 
    \Iterator,
    \JsonSerializable
{
  use HasData;

  protected array $questions        = [];
  protected array $questionsByQid   = [];
  protected array $questionsByTitle = [];

  public int $json_flags = 0;

  protected function dataProperty(): string
  {
    return 'questions';
  }

  public function __construct(Helper $helper, mixed $qspec, array $opts=[])
  {
    if (is_numeric($qspec))
    { // The sid was passed.
      $qspec = $helper->get_questions($qspec, $opts);
    }

    if (!is_array($qspec))
    { // Not a valid spec.
      throw new \Exception("Invalid question definitions");
    }

    if (isset($opts['json_flags']) && is_int($opts['json_flags']))
    {
      $this->json_flags = $opts['json_flags'];
    }

    foreach ($qspec as $qdef)
    {
      $question = new Question($this, $qdef);
      $this->questions[] = $question;
      $qid = $question->qid;
      $title = $question->title;
      $this->questionsByQid[$qid] = $question;
      $this->questionsByTitle[$title] = $question;
    }
  }

  // Override certain methods from HasData trait.

  public function __isset($key): bool
  {
    if (isset($this->questionsByQid[$key]))
    {
      return true;
    }
    elseif (isset($this->questionsByTitle[$key]))
    {
      return true;
    }
    return false;
  }

  public function __get($key): mixed
  {
    if (isset($this->questionsByQid[$key]))
    {
      return $this->questionsByQid[$key];
    }
    elseif (isset($this->questionsByTitle[$key]))
    {
      return $this->questionsByTitle[$key];
    }
    return null;
  }

  // Countable interface

  public function count(): int
  {
    return count($this->questions);
  }

  // Iterator interface

  public function current(): mixed
  {
    return current($this->questions);
  }

  public function key(): mixed
  {
    return key($this->questions);
  }

  public function next(): void
  {
    next($this->questions);
  }

  public function rewind(): void
  {
    reset($this->questions);
  }

  public function valid(): bool
  {
    return key($this->questions) !== null;
  }

  // Stringable interface

  public function __toString(): string
  {
    return json_encode($this, $this->json_flags);
  }

}
