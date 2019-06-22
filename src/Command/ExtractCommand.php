<?php
namespace Civi\Strings\Command;

use Civi\Strings\Parser\JsParser;
use Civi\Strings\Parser\PhpTreeParser;
use Civi\Strings\Parser\SmartyParser;
use Civi\Strings\Parser\SettingParser;
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
      ->setHelp('Extract strings from any mix of PHP, Smarty, JS, HTML files.')
      ->addArgument('files', InputArgument::IS_ARRAY, 'Files from which to extract strings. Use "-" to accept file names from STDIN')
      ->addOption('append', 'a', InputOption::VALUE_NONE, 'Append to file. (Use with --out)')
      ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'Base directory name (for constructing relative paths)', realpath(getcwd()))
      ->addOption('header', NULL, InputOption::VALUE_REQUIRED, 'Header file to prepend to output.')
      ->addOption('msgctxt', NULL, InputOption::VALUE_REQUIRED, 'Set default msgctxt for all strings')
      ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output file. (Default: stdout)');
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->parsers = array();
    $this->parsers['js'] = new JsParser();
    $this->parsers['html'] = new JsParser();
    $this->parsers['php'] = new PhpTreeParser();
    $this->parsers['smarty'] = new SmartyParser($this->parsers['php']);
    $this->parsers['setting'] = new SettingParser();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $defaults = array();
    if ($input->getOption('msgctxt')) {
      $defaults['msgctxt'] = $input->getOption('msgctxt');
    }
    $this->pot = new Pot($input->getOption('base'), array(), $defaults);

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
      if ($input->getOption('header')) {
        $output->write(file_get_contents($input->getOption('header')));
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
      $content = '';
      ## If header is supplied and if we're starting a new file.
      if ($input->getOption('header')) {
        if (!file_exists($input->getOption('out')) || !$input->getOption('append')) {
          $content .= file_get_contents($input->getOption('header'));
        }
      }
      $content .= $this->pot->toString($input);
      file_put_contents($input->getOption('out'), $content, $input->getOption('append') ? FILE_APPEND : NULL);
      $progress->finish();
    }
  }

  protected function findFiles($paths) {
    $actualFiles = array();

    $exclude_dirs = ['vendor', 'node_modules'];
    sort($paths);
    $paths = array_unique($paths);

    foreach ($paths as $path) {
      if (is_dir($path)) {
        if (!in_array(basename($path), $exclude_dirs)) {
          $children = array();

          $d = dir($path);
          while (FALSE !== ($entry = $d->read())) {
            if ($entry == '.' || $entry == '..') {
              continue;
            }
            $children[] = rtrim($path, '/') . '/' . $entry;
          }
          $d->close();

          $actualFiles = array_merge($actualFiles, $this->findFiles($children));
        }
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
    elseif (preg_match('/\.setting.php$/', $file)) {
      $parser = 'setting';
    }
    elseif (preg_match('/\.(tpl|hlp)$/', $file)) {
      $parser = 'smarty';
    }
    elseif (preg_match('/\.php$/', $file) || preg_match(':^<\?php:', $content) || preg_match(':^#![^\n]+php:', $content)) {
      $parser = 'php';
    }

    return $parser ? $this->parsers[$parser] : NULL;
  }

}
