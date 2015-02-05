<?php
namespace Civi\Strings\Command;

use Civi\Strings\Parser\JsParser;
use Civi\Strings\Parser\PhpParser;
use Civi\Strings\Parser\SmartyParser;
use Civi\Strings\Pot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extract strings from a list of files.
 *
 * @package Civi\Strings\Command
 */
class ExtractCommand extends Command {

  /**
   * @var array
   *   Array(string $name => ParserInterface $parser)
   */
  protected $parsers;

  /**
   * @var Pot
   */
  protected $pot;

  /**
   * @var null|resource
   */
  protected $stdin;

  public function __construct($name = NULL, $stdin = NULL) {
    parent::__construct($name); // TODO: Change the autogenerated stub
    $this->stdin = $stdin ? $stdin : STDIN;
  }

  protected function configure() {
    $this
      ->setName('civistrings')
      ->setDescription('Extract strings')
      ->setHelp('Extract files any mix of PHP, Smarty, JS, HTML files.')
      ->addArgument('files', InputArgument::IS_ARRAY, 'Files from which to extract strings. Use "-" to accept file names from STDIN')
      ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'Base directory name (for constructing relative paths)', realpath(getcwd()))
      ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output file. (Default: stdout)');
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->parsers = array();
    $this->parsers['js'] = new JsParser();
    $this->parsers['html'] = new JsParser();
    $this->parsers['php'] = new PhpParser();
    $this->parsers['smarty'] = new SmartyParser($this->parsers['php']);
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->pot = new Pot($input->getOption('base'));

    $files = $input->getArgument('files');

    if (in_array('-', $files)) {
      $files = array_merge(
        $files,
        explode("\n", stream_get_contents($this->stdin))
      );
    }

    $actualFiles = $this->findFiles($files);

    if (!$input->getOption('out')) {
      foreach ($actualFiles as $file) {
        $this->extractFile($file);
      }
      $output->write($this->pot->toString($input));
    }
    else {
      $progress = new ProgressHelper();
      $progress->start($output, 1 + count($actualFiles));
      $progress->advance();
      foreach ($actualFiles as $file) {
        $this->extractFile($file);
        $progress->advance();
      }
      file_put_contents($input->getOption('out'), $this->pot->toString($input));
      $progress->finish();
    }
  }

  protected function findFiles($paths) {
    $actualFiles = array();

    sort($paths);
    $paths = array_unique($paths);

    foreach ($paths as $path) {
      if (is_dir($path)) {
        $children = array();

        $d = dir($path);
        while (FALSE !== ($entry = $d->read())) {
          if ($entry == '.' || $entry == '..') {
            continue;
          }
          $children[] = $path . '/' . $entry;
        }
        $d->close();

        $actualFiles = array_merge($actualFiles, $this->findFiles($children));
      }
      elseif (file_exists($path)) {
        $actualFiles[] = $path;
      }
    }

    return $actualFiles;
  }

  /**
   * @param string $file
   */
  protected function extractFile($file) {
    $content = @file_get_contents($file);

    $parser = $this->pickParser($file, $content);
    if (!$parser) {
      return;
    }

    $parser->parse($file, $content, $this->pot);
  }

  /**
   * @param string $file
   * @param string $content
   * @return Object|NULL
   */
  protected function pickParser($file, $content) {
    $file = realpath($file);

    $parser = NULL;
    if (preg_match('/~$/', $file)) {
      // skip
    }
    elseif (preg_match('/\.js$/', $file)) {
      $parser = 'js';
    }
    elseif (preg_match('/\.html$/', $file)) {
      $parser = 'html';
    }
    elseif (preg_match('/\.tpl$/', $file)) {
      $parser = 'smarty';
    }
    elseif (preg_match('/\.php$/', $file) || preg_match(':^<\?php:', $content) || preg_match(':^#![^\n]+php:', $content)) {
      $parser = 'php';
    }

    return $parser ? $this->parsers[$parser] : NULL;
  }

}
