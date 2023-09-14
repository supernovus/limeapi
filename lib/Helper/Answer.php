<?php

namespace LimeAPI\Helper;

/**
 * A class representing an individual answer from a question.
 * 
 * @package LimeAPI\Helper
 */
class Answer extends DefinitionData
{
  /** 
   * The immediate parent of this question. 
   * 
   * If this is a top-level question the parent will be the
   * same as the `rootQuestions` property.
   * 
   * Otherwise it will be the parent Question instance.
   */
  public readonly Question $parent;

  public function __construct(Question $parent, array $data)
  {
    parent::__construct($parent->rootQuestions, $data);    
    $this->parent = $parent;
  }

  public function getString(): string
  {
    return $this->defData['strings'][0]['answer'] ?? $this->defData['code'];
  }
}
