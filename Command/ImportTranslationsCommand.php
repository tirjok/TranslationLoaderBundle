<?php
/**
 * @namespace Asm\TranslationLoaderBundle\Command
 */
namespace Asm\TranslationLoaderBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\Catalogue\DiffOperation;
use Symfony\Component\Translation\Catalogue\MergeOperation;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Finder\Finder;
use Asm\TranslationLoaderBundle\Entity\Translation;

/**
 * Class DumpTranslationFiles
 *
 * @package Asm\TranslationLoaderBundle\Command
 * @author marc aschmann <maschmann@gmail.com>
 * @uses Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand
 * @uses Symfony\Component\Console\Input\InputArgument
 * @uses Symfony\Component\Console\Input\InputInterface
 * @uses Symfony\Component\Console\Input\InputOption
 * @uses Symfony\Component\Console\Output\OutputInterface
 * @uses Symfony\Component\Translation\Catalogue\DiffOperation
 * @uses Symfony\Component\Translation\Catalogue\MergeOperation
 * @uses Symfony\Component\Translation\MessageCatalogue
 */
class ImportTranslationsCommand extends ContainerAwareCommand
{

    /**
     * message catalogue container
     *
     * @var array
     */
    private $catalogues = array();


    /**
     * translation loader container
     *
     * @var array
     */
    private $loaders = array();


    /**
     * symfony di container
     *
     * @var null
     */
    private $container = null;


    /**
     * command configuration
     */
    protected function configure()
    {
        $this
            ->setName('asm:translations:import')
            ->setDescription('Import translations from all bundles.')
            ->addOption(
                'clear',
                'c',
                null,
                'clear database before import'
            );
    }


    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');
        $output->writeln('<info>Translation file importer</info>');
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');
        $output->writeln('<info>importing all available translation files ...</info>');

        $this->container   = $this->getContainer();

        if ($input->getOption('clear')) {
            $output->writeln('<comment>deleting all translations from database...</comment>');
            $output->writeln('<info>--------------------------------------------------------------------------------</info>');
            $output->writeln('');
            $this->container->get('doctrine')
                ->getManager()
                ->createQuery('DELETE FROM AsmTranslationLoaderBundle:Translation')
                ->execute();
        }

        $this->generateCatalogues($output);
        $this->importCatalogues($output);

        $output->writeln('<info>--------------------------------------------------------------------------------</info>');
        $output->writeln('<comment>finished!</comment>');
    }


    /**
     * iterate all bundles and generate a message catalogue for each locale
     *
     * @param OutputInterface $output
     * @throws \ErrorException
     */
    private function generateCatalogues($output)
    {
        $translationWriter = $this->container->get('translation.writer');
        $supportedFormats  = $translationWriter->getFormats();

        // iterate all bundles and get their translations
        foreach (array_keys($this->container->getParameter('kernel.bundles')) as $bundle) {
            $currentBundle   = $this->getApplication()->getKernel()->getBundle($bundle);
            $translationPath = $currentBundle->getPath().'/Resources/translations';

            // load any existing translation files
            if (is_dir($translationPath)) {
                $output->writeln('<info>searching ' . $bundle . ' translations</info>');
                $output->writeln('<info>--------------------------------------------------------------------------------</info>');

                $finder = new Finder();
                $files = $finder
                    ->files()
                    ->in($translationPath);

                foreach ($files as $file) {
                    $extension = explode('.', $file->getFilename());
                    // domain.locale.extension
                    if (3 == count($extension)) {
                        $fileExtension = array_pop($extension);
                        if (in_array($fileExtension, $supportedFormats)) {
                            $locale = array_pop($extension);
                            $domain = array_pop($extension);

                            if (empty($this->catalogues[$locale])) {
                                $this->catalogues[$locale] = new MessageCatalogue($locale);
                            }

                            if (empty($this->loaders[$fileExtension])) {
                                try {
                                    $this->loaders[$fileExtension] = $this->container->get('translation.loader.' . $fileExtension);
                                } catch (\Exception $e) {
                                    throw new \ErrorException('could not find loader for ' . $fileExtension . ' files!');
                                }
                            }

                            $output->writeln('<comment>loading ' . $file->getFilename() . ' with locale ' . $locale . ' and domain ' . $domain . '</comment>');
                            $currentCatalogue = $this->loaders[$fileExtension]->load($file->getPathname(), $locale, $domain);
                            $this->catalogues[$locale]->addCatalogue($currentCatalogue);
                        }
                    }
                }
                $output->writeln('');
            }
        }
    }


    /**
     * look through the catalogs and store them to database
     *
     * @param OutputInterface $output
     */
    private function importCatalogues($output)
    {
        /**
         * since performance might be an issue and also there's no usefull way using
         * INSERT ON DUPICATE KEY UPDATE with doctrine.. maybe use dbal to batch process...
         * @todo make this configurable, so different databases/ems could be used
         */
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->container->get('doctrine')->getManager();
        $repository = $em->getRepository('AsmTranslationLoaderBundle:Translation');
        $output->writeln('<info>inserting all translations</info>');
        $output->writeln('<info>--------------------------------------------------------------------------------</info>');

        foreach ($this->catalogues as $locale => $catalogue) {

            $output->write('<comment>' . $locale . ': </comment>');
            foreach ($catalogue->getDomains() as $domain) {
                foreach ($catalogue->all($domain) as $key => $message) {
                    /** @var \Asm\TranslationLoaderBundle\Entity\Translation $translation */
                    $translation = $repository->findOneBy(
                        array(
                            'transKey'      => $key,
                            'transLocale'   => $locale,
                            'messageDomain' => $domain,
                        )
                    );

                    // insert if no entry exists
                    if (!$translation) {
                        /** @var \Asm\TranslationLoaderBundle\Entity\Translation $translation */
                        $translation = new Translation();
                        $translation->setTransKey($key);
                        $translation->setTransLocale($locale);
                        $translation->setMessageDomain($domain);
                    }

                    // and in either case we want to add a message :-)
                    $translation->setTranslation($message);
                    $em->persist($translation);
                    $em->flush();
                }
                $output->write('<info> ... ' . $domain . '.' . $locale . '</info>');
            }
            $output->writeln('');
        }
    }
}