<?php
/**
 * @file copy_comments.php
 *
 * Author: Nigel Cunningham
 *
 * Copy the comments from an old drupal.make.yml into a newly generated
 * file. This is necessary because the Symfony parses drops comments (at
 * least on a quick step-through of the code).
 **/

function customError($error_level, $error_message, $error_file, $error_line, $error_context) {
  fwrite(STDERR, $error_message);
  exit(1);
}

set_error_handler("customError");

class CommentMerger {
  /**
   * @var $oldLines
   *   The content of the previous version of the Drush makefile.
   */
  private $oldLines;

  /**
   * @var $newLines
   *   The content of the new version of the Drush makefile.
   */
  private $newLines;

  /**
   * @var $newLineIndex
   *   The last new line that was written.
   */
  private $newLineIndex = 0;

  /**
   * @var $oldLineIndex
   *   The last oldline that was considered.
   */
  private $oldLineIndex = 0;

  /**
   * @var $output
   *   The output of our labours.
   */
  private $output = '';

  /**
   * Constructor - check args and file access.
   *
   * @param $args
   *   The arguments supplied on the command line.
   */
  public function __construct($args) {
    if (php_sapi_name() !== 'cli') {
      echo("The tool is made to run from the command line only, sorry.");
      exit(1);
    }

    if (count($args) != 3) {
      $binaryname = $args[0];
      $message = <<< EOF
Invoke as {$binaryname} <original_yaml> <new_yaml>

The new and old files will be read, with comments from the old file being merged into
the new file content. The resulting content is output to stdout, and any errors to stderr.
EOF;
      echo $message;
      exit(1);
    }

    $oldFileName = $args[1];
    if (!file_Exists($oldFileName)) {
      // Treat as an empty file.
      $oldFileContent = '';
    }
    else {
      $oldFileContent = file_get_contents($oldFileName);
    }

    $newFileName = $args[2];
    if (!file_Exists($newFileName)) {
      // Treat as an empty file.
      $newFileContent = '';
    }
    else {
      $newFileContent = file_get_contents($newFileName);
    }

    $this->oldLines = explode("\n", $oldFileContent);
    $this->newLines = explode("\n", $newFileContent);

    $this->oldContent = yaml_parse($oldFileContent);
    $this->newContent = yaml_parse($newFileContent);

  }

  private function lineIsComment($line) {
    $firstChar = substr(trim($line), 0, 1);
    return ($firstChar == '#');
  }

  /**
   * Write a set of lines from the new file to stdout, starting from where we last stopped.
   *
   * @param int $finishAt
   *   Index of the last line to write.
   */
  public function writeNewFileLines($finish_at = NULL) {

    if (is_null($finish_at)) {
      $finish_at = count($this->newLines) - 1;
    }

    while ($this->newLineIndex <= $finish_at) {
      $this->output .= $this->newLines[$this->newLineIndex] . "\n";
      $this->newLineIndex++;
    }
  }

  /**
   * Get the location of list of parents in the new file.
   *
   * @param array $parents
   *   The list of parents returned by (eg) getLocationFromOldPosition
   *
   * @return integer|FALSE
   *   The line number of the new location, or FALSE.
   */
  public function getNewLocationFromOld($parents) {
    // Confirm the line exists in the new file.
    $checking = $this->newContent;
    $checkingKey = TRUE;
    for ($i = 0; $i < count($parents); $i++) {
      if ($checkingKey) {
        if (!array_key_exists($parents[$i], $checking)) {
          return FALSE;
        }
        if ($parents[$i] == 'patch') {
          $checkingKey = FALSE;
        }
        $checking = $checking[$parents[$i]];
      }
      else {
        $values = array_flip($checking);
        if (!array_key_exists($parents[$i], $values)) {
          return FALSE;
        }
      }
    }

    // We know there's a matching line in the new file. Get its location.
    $parentLevel = 0;
    for ($i = 0; $i < count($this->newLines); $i++) {
      $thisLine = $this->newLines[$i];
      $indentLevel = (strlen($thisLine) - strlen(ltrim($thisLine))) / 2;

      $thisLine = trim($thisLine);
      if (substr($thisLine, -1) == ':') {
        $thisLine = substr($thisLine, 0, strlen($thisLine) - 1);
      } elseif (substr($thisLine, 0, 1) == "-") {
        // A patch - strip the leading hyphen and the quotes around the URL
        $thisLine = explode("'", $thisLine);
        $thisLine = $thisLine[1];
      }

      if ($indentLevel == $parentLevel && trim($thisLine) == $parents[$parentLevel]) {
        $parentLevel++;
      }
      if ($parentLevel == count($parents)) {
        return $i;
      }
    }

    // We shouldn't reach here.
    return FALSE;
  }

  /**
   * Get the list of YAML parents of a comment.
   *
   * @param array $parents
   *   An array of parents in the file.
   */
  public function getLocationFromOldPosition() {
    $parents = [];

    $thisLine = $this->oldLines[$this->oldLineIndex];
    $oldIndentLevel = (strlen($thisLine) - strlen(ltrim($thisLine))) / 2;

    // Go backwards, locating parents.
    for ($i = $this->oldLineIndex - 1; $i >= 0; $i--) {
      $thisLine = $this->oldLines[$i];
      $indentLevel = (strlen($thisLine) - strlen(ltrim($thisLine))) / 2;
      if ($indentLevel < $oldIndentLevel) {
        $thisLine = trim($thisLine);
        if (substr($thisLine, -1) == ':') {
          $thisLine = substr($thisLine, 0, strlen($thisLine) - 1);
        }
        elseif (substr($thisLine, 0, 1) == "-") {
          // A patch - strip the leading hyphen and the quotes around the URL
          $thisLine = explode("'", $thisLine);
          $thisLine = $thisLine[1];
        }
        $parents[] = $thisLine;
        $oldIndentLevel--;
      }
      if (!$oldIndentLevel) {
        break;
      }
    }

    $parents = array_reverse($parents);
    return $parents;
  }

  /**
   * Perform the merging of comments from the old version into the new.
   *
   * Assumptions:
   * - Drush has sorted both the new file and the old, reading the old file and
   * stripping comments in the output. The two main differences between the
   * files are thus version numbers and comments. (There may also be modules
   * added and removed.
   */
  public function merge() {
    $startOfFile = TRUE;

    // Locate the next comment.
    while ($this->oldLineIndex < count($this->oldLines)) {
      $thisLine = $this->oldLines[$this->oldLineIndex];

      if ($this->lineIsComment($thisLine)) {
        if ($startOfFile) {
          $this->output .= $thisLine . "\n";
        }
        else {
          $oldCommentParent = $this->getLocationFromOldPosition();
          $newCommentParent = $this->getNewLocationFromOld($oldCommentParent);

          // It must have existed in the old file, but may not in the new.
          if ($newCommentParent) {
            // Output the lines in the new file up to and including the
            // newCommentParent.
            $this->writeNewFileLines($newCommentParent);
            $this->output .= $thisLine . "\n";
          }
        }
      }
      else {
        $startOfFile = FALSE;
      }
      $this->oldLineIndex++;
    }

    $this->writeNewFileLines();

    // Trim the final "\n" from the output and send it to STDOUT.
    $this->output = substr($this->output, 0, strlen($this->output) - 1);
    fwrite(STDOUT, $this->output);
  }

}

$Merger = new CommentMerger($argv);
$Merger->merge();

