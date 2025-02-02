<?php

namespace Lexik\Bundle\TranslationBundle\Command;

use Lexik\Bundle\TranslationBundle\Model\File;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application;
use Symfony\Bundle\FrameworkBundle\Command\CacheClearCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Export translations from the database in to files.
 *
 * @author Cédric Girard <c.girard@lexik.fr>
 */
class ExportTranslationsCommand extends ContainerAwareCommand
{
    /**
     * @var Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * @var Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    /**
     * (non-PHPdoc)
     * @see Symfony\Component\Console\Command.Command::configure()
     */
    protected function configure()
    {
        $this->setName('lexik:translations:export');
        $this->setDescription('Export translations from the database to files.');

        $this->addOption('locales', 'l', InputOption::VALUE_OPTIONAL, 'Only export files for given locales. e.g. "--locales=en,de"', null);
        $this->addOption('domains', 'd', InputOption::VALUE_OPTIONAL, 'Only export files for given domains. e.g. "--domains=messages,validators"', null);
        $this->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Force the output format.', null);
    }

    /**
     * (non-PHPdoc)
     * @see Symfony\Component\Console\Command.Command::execute()
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $filesToExport = $this->getFilesToExport();

        if (count($filesToExport) > 0) {
            foreach ($filesToExport as $file) {
                $this->exportFile($file);
            }
                
        } else {
            $this->output->writeln('<comment>No translation\'s files in the database.</comment>');
        }
        
        
    }

    /**
     * Returns all file to export.
     *
     * @return array
     */
    protected function getFilesToExport()
    {
        $locales = array();
        $domains = array();

        $repository = $this->getContainer()
            ->get('lexik_translation.file.manager')
            ->getFileRepository();

        return $repository->findForLoalesAndDomains($locales, $domains);
    }

    /**
     * Get translations to export and export translations into a file.
     *
     * @param File $file
     */
    protected function exportFile(File $file)
    {
        $rootDir = $this->getContainer()->getParameter('kernel.root_dir');

        $this->output->writeln(sprintf('<info># Exporting "%s/%s":</info>', $file->getPath(), $file->getName()));

        // we only export updated translations in case of the file is located in vendor/
        $onlyUpdated = (substr($file->getPath(), 0, 6) == 'vendor');

        $translations = $this->getContainer()
            ->get('lexik_translation.trans_unit.manager')
            ->getTransUnitRepository()
            ->getTranslationsForFile($file, $onlyUpdated);

        if (count($translations) > 0) {
            $format = $file->getExtention();

            // we don't write vendors file, translations will be exported in app/Resources/translations
            $outputPath = (substr($file->getPath(), 0, 6) == 'vendor') ? sprintf('%s/Resources/translations', $rootDir) : sprintf('%s/../%s', $rootDir, $file->getPath());
            $outputFile = sprintf('%s/%s.%s.%s', $outputPath, $file->getDomain(), $file->getLocale(), $format);

            $translations = $this->mergeExistingTranslations($file, $outputFile, $translations);
            $this->doExport($outputFile, $translations, $format);
        } else {
            $this->output->writeln('<comment>No translations to export.</comment>');
        }
    }

    /**
     * If the output file exists we merge existing translations with those from the database.
     *
     * @param File $file
     * @param string $outputFile
     * @param array $translations
     * @return array
     */
    protected function mergeExistingTranslations($file, $outputFile, $translations)
    {
        if (file_exists($outputFile)) {
            $loader = $this->getContainer()->get('lexik_translation.translator')->getLoader($file->getExtention());
            $messageCatalogue = $loader->load($outputFile, $file->getLocale(), $file->getDomain());

            $translations = array_merge($messageCatalogue->all($file->getDomain()), $translations);
        }

        return $translations;
    }

    /**
     * Export translations.
     *
     * @param string $outputFile
     * @param array $translations
     * @param string $format
     */
    protected function doExport($outputFile, $translations, $format)
    {
        $this->output->writeln(sprintf('<comment>Output file: %s</comment>', $outputFile));
        $this->output->write(sprintf('<comment>%d translations to export: </comment>', count($translations)));

        try {
            $exporter = $this->getContainer()->get('lexik_translation.exporter_collector')->getByFormat($format);
            $exported = $exporter->export($outputFile, $translations);

            $this->output->writeln($exported ? '<comment>success</comment>' : '<error>fail</error>');
        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>"%s"</error>', $e->getMessage()));
        }
    }
    
    
    protected static function deleteCache($dirPath) {

        if (! is_dir($dirPath)) {
            throw new InvalidArgumentException('$dirPath must be a directory');
        }
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteCache($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dirPath);
    }
}