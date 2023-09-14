<?php

namespace LimeAPI\Helper;

/**
 * A class representing a single question or sub-question.
 * 
 * @package LimeAPI\Helper
 */
class Question extends DefinitionData
{
  /** 
   * The immediate parent of this question. 
   * 
   * If this is a top-level question the parent will be the
   * same as the `rootQuestions` property.
   * 
   * Otherwise it will be the parent Question instance.
   */
  public readonly Questions|Question $parent;

  /**
   * Is this a sub-question?
   * @var bool
   */
  public readonly bool $isSubquestion;

  protected $subquestions = [];
  protected $subsByTitle  = [];
  protected $subsByQid    = [];
  
  protected $answers       = [];
  protected $answersByAid  = [];
  protected $answersByCode = [];

  public function __construct(Questions|Question $parent, array $data)
  {
    if ($parent instanceof Questions)
    {
      parent::__construct($parent, $data);
      $this->isSubquestion = false;
    }
    else 
    {
      parent::__construct($parent->rootQuestions, $data);
      $this->isSubquestion = true;
    }

    $this->parent = $parent;

    if (isset($data['subquestions']) && is_array($data['subquestions']))
    {
      foreach ($data['subquestions'] as $subdef)
      {
        $subq = new Question($this, $subdef);
        $this->subquestions[] = $subq;
        $qid = $subq->qid;
        $title = $subq->title;
        $this->subsByQid[$qid] = $subq;
        $this->subsByTitle[$title] = $subq;
      }
    }

    if (isset($data['answers']) && is_array($data['answers']))
    {
      foreach ($data['answers'] as $ansdef)
      {
        $answer = new Answer($this, $ansdef);
        $this->answers[] = $answer;
        $aid = $answer->aid;
        $code = $answer->code;
        $this->answersByAid[$aid] = $answer;
        $this->answersByCode[$code] = $answer;
      }
    }
  }

  public function subquestions()
  {
    return $this->subquestions;
  }

  public function subquestion($key): ?Question
  {
    if (isset($this->subsByQid[$key]))
    {
      return $this->subsByQid[$key];
    }
    elseif (isset($this->subsByTitle[$key]))
    {
      return $this->subsByTitle[$key];
    }
    return null;
  }

  public function answers()
  {
    return $this->answers;
  }

  public function answer($key): ?Answer
  {
    if (isset($this->answersByAid[$key]))
    {
      return $this->answersByAid[$key];
    }
    elseif (isset($this->answersByCode[$key]))
    {
      return $this->answersByCode[$key];
    }
    return null;
  }

  public function getString(): string
  {
    return $this->defData['strings'][0]['question'] ?? $this->defData['title'];
  }

  public function multipleChoice(array $responseData, array $opts=[])
  {
    $codeMap  = $opts['map']  ?? false;
    $listCode = $opts['code'] ?? false;
    $listText = $opts['text'] ?? false;

    $fatal = $opts['fatal'] ?? true;
    $error = null;

    if ($this->isSubquestion)
    {
      $error = "Cannot call 'multipleChoice()' on sub-question";
    }
    if ($this->type !== 'M')
    {
      $error = "Question 'type' was not 'M'";
    }
    if (count($this->subquestions) === 0)
    {
      $error = "No sub-questions";
    }

    if ($error)
    {
      if ($fatal)
      {
        throw new \Exception($error);
      }
      else
      {
        error_log($error);
        return null;
      }
    }

    $choices = [];

    $qtitle = $this->title;
    foreach ($this->subquestions as $subq)
    {
      $stitle = $subq->title;
      $responseKey = $qtitle.'['.$stitle.']';
      if (isset($responseData[$responseKey]))
      {
        $selected = $responseData[$responseKey] === 'Y';
        if ($codeMap)
        { // A map of sub-question codes to boolean selected value.
          $choices[$stitle] = $selected;
        }
        else
        { // A flat list of selected sub-questions only.
          if ($selected)
          {
            if ($listCode && $listText)
            { // Both code and label.
              $sitem = 
              [
                'code' => $stitle,
                'text' => $subq->getString(),
              ];
            }
            elseif ($listCode)
            { // Just the code (subquestion.title)
              $sitem = $stitle;
            }
            elseif ($listText)
            { // Just the text (subtitle.strings[0].question)
              $sitem = $subq->getString();
            }
            else
            { // Use the sub-question object itself.
              $sitem = $subq;
            }

            $choices[] = $sitem;
          }
        }
      }
    }

    return $choices;
  }

}
