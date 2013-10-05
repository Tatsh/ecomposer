<?php
namespace EComposer\Command;

use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Factory as ComposerFactory;
use Composer\Installer;
use Composer\IO\ConsoleIO;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\RootPackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates ebuilds for a package available with composer.
 *
 * This should generally only be used with a package that has a binary (like
 *   PHPUnit or PHP Analyzer).
 */
class GenerateEbuildCommand extends Command
{
    protected $manager;
    protected $packageName;
    protected $version;
    protected $composer;
    protected $io;
    protected $config;
    protected $repositoryManager;
    protected $policy;
    protected $package;
    protected $pool;

    protected function configure()
    {
        $this->setName('ebuild:generate')
                ->setDescription('Generates ebuilds for a package')
                ->addArgument('name', InputArgument::REQUIRED, 'Composer package name')
                ->addArgument('version', InputArgument::OPTIONAL, 'Version of the package', 'dev-master');
    }

    protected function generateConfig()
    {
        return array(
            'name' => 'temp/ecomposer-pkg',
            'type' => 'library',
            'vendor-dir' => 'ecomposer-vendor',
            'bin-dir' => 'ecomposer-bin',
            'require' => array(
                $this->packageName => $this->version,
            ),
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->packageName = $input->getArgument('name');
        $this->version = $input->getArgument('version');
        $this->io = new ConsoleIO($input, $output, $this->getHelperSet());
        $this->composer = ComposerFactory::create($this->io, $this->generateConfig()/*, true */);
        $this->repositoryManager = $this->composer->getRepositoryManager();
        $this->package = $this->composer->getPackage();
        $this->policy = new DefaultPolicy($this->package->getPreferStable());
        $this->pool = $this->createPool();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $platformRepo = new PlatformRepository();
        $installedRepo = new CompositeRepository(array(
            $this->repositoryManager->getLocalRepository(),
        ));
        $request = $this->createRequest($this->pool, $this->package, $platformRepo);
        $links = $this->package->getRequires();

        $request->updateAll();

        foreach ($links as $link) {
            $request->install($link->getTarget(), $link->getConstraint());
        }

        // solve dependencies
        $solver = new Solver($this->policy, $this->pool, $installedRepo);
        try {
            $operations = $solver->solve($request);
        }
        catch (SolverProblemsException $e) {
            $this->io->write('<error>Your requirements could not be resolved to an installable set of packages.</error>');
            $this->io->write($e->getMessage());

            return false;
        }
    }

    private function createPool()
    {
        $minimumStability = $this->package->getMinimumStability();
        $stabilityFlags = $this->package->getStabilityFlags();

//         if (!$this->update && $this->locker->isLocked()) {
//             $minimumStability = $this->locker->getMinimumStability();
//             $stabilityFlags = $this->locker->getStabilityFlags();
//         }

        return new Pool($minimumStability, $stabilityFlags);
    }

    private function createRequest(Pool $pool, RootPackageInterface $rootPackage, PlatformRepository $platformRepo)
    {
        $request = new Request($pool);

        $constraint = new VersionConstraint('=', $rootPackage->getVersion());
        $constraint->setPrettyString($rootPackage->getPrettyVersion());
        $request->install($rootPackage->getName(), $constraint);

        $fixedPackages = $platformRepo->getPackages();
//         if ($this->additionalInstalledRepository) {
//             $additionalFixedPackages = $this->additionalInstalledRepository->getPackages();
//             $fixedPackages = array_merge($fixedPackages, $additionalFixedPackages);
//         }

        // fix the version of all platform packages + additionally installed packages
        // to prevent the solver trying to remove or update those
        $provided = $rootPackage->getProvides();
        foreach ($fixedPackages as $package) {
            $constraint = new VersionConstraint('=', $package->getVersion());
            $constraint->setPrettyString($package->getPrettyVersion());

            // skip platform packages that are provided by the root package
            if ($package->getRepository() !== $platformRepo ||
                !isset($provided[$package->getName()]) ||
                !$provided[$package->getName()]->getConstraint()->matches($constraint)) {
                $request->install($package->getName(), $constraint);
            }
        }

        return $request;
    }
}
