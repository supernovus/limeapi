<?php 

namespace LimeAPI\Helper;

/**
 * An abstract base class to provide fallback getters for instances with definition data.
 */
abstract class DefinitionData
  implements 
    \ArrayAccess,
    \JsonSerializable
{
  use HasData;

  protected array $defData;

  /** The top-level Questions instance. */
  public readonly Questions $rootQuestions;

  public function __construct(Questions $root, array $data)
  {
    $this->rootQuestions = $root;
    $this->defData = $data;
  }

  protected function dataProperty(): string
  {
    return 'defData';
  }

  // Stringable interface

  public function __toString(): string
  {
    return json_encode($this, $this->rootQuestions->json_flags);
  }
}
