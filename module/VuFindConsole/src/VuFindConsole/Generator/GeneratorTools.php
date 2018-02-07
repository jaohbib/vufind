<?php
/**
 * Generator tools.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2018.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Generator
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
namespace VuFindConsole\Generator;

use Interop\Container\ContainerInterface;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Reflection\ClassReflection;
use Zend\Console\Console;

/**
 * Generator tools.
 *
 * @category VuFind
 * @package  Generator
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class GeneratorTools
{
    /**
     * Zend Framework configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     *
     * @param array $config Zend Framework configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Extend a class defined somewhere in the service manager or its child
     * plugin managers.
     *
     * @param ContainerInterface $container Service manager
     * @param string             $class     Class name to extend
     * @param string             $target    Target module in which to create new
     * service
     *
     * @return bool
     * @throws \Exception
     */
    public function extendClass(ContainerInterface $container, $class, $target)
    {
        // Set things up differently depending on whether this is a top-level
        // service or a class in a plugin manager.
        if ($container->has($class)) {
            $factory = $this->getFactoryFromContainer($container, $class);
            $configPath = ['service_manager'];
        } else {
            $pm = $this->getPluginManagerContainingClass($container, $class);
            $apmFactory = new \VuFind\ServiceManager\AbstractPluginManagerFactory();
            $pmKey = $apmFactory->getConfigKey(get_class($pm));
            $factory = $this->getFactoryFromContainer($pm, $class);
            $configPath = ['vufind', 'plugin_managers', $pmKey];
        }

        // No factory found? Throw an error!
        if (!$factory) {
            throw new \Exception('Could not find factory for ' . $class);
        }

        // Create the custom subclass.
        $newClass = $this->createSubclassInModule($class, $target);

        // Finalize the local module configuration -- create a factory for the
        // new class, and set up the new class as an alias for the old class.
        $factoryPath = array_merge($configPath, ['factories', $newClass]);
        $this->writeNewConfig($factoryPath, $factory, $target);
        $aliasPath = array_merge($configPath, ['aliases', $class]);
        // Don't back up the config twice -- the first backup from the previous
        // write operation is sufficient.
        $this->writeNewConfig($aliasPath, $newClass, $target, false);

        return true;
    }

    /**
     * Get a list of factories in the provided container.
     *
     * @param ContainerInterface $container Container to inspect
     *
     * @return array
     */
    protected function getAllFactoriesFromContainer(ContainerInterface $container)
    {
        // There is no "getFactories" method, so we need to use reflection:
        $reflectionProperty = new \ReflectionProperty($container, 'factories');
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($container);
    }

    /**
     * Get a factory from the provided container (or null if undefined).
     *
     * @param ContainerInterface $container Container to inspect
     * @param string             $class     Class whose factory we want
     *
     * @return string
     */
    protected function getFactoryFromContainer(ContainerInterface $container, $class)
    {
        $factories = $this->getAllFactoriesFromContainer($container);
        return isset($factories[$class]) ? $factories[$class] : null;
    }

    /**
     * Search all plugin managers for one containing the requested class (or return
     * null if none found).
     *
     * @param ContainerInterface $container Service manager
     * @param string             $class     Class to search for
     *
     * @return ContainerInterface
     */
    protected function getPluginManagerContainingClass(ContainerInterface $container,
        $class
    ) {
        $factories = $this->getAllFactoriesFromContainer($container);
        foreach (array_keys($factories) as $service) {
            if (substr($service, -13) == 'PluginManager') {
                $pm = $container->get($service);
                if (null !== $this->getFactoryFromContainer($pm, $class)) {
                    return $pm;
                }
            }
        }
        return null;
    }

    /**
     * Extend a service defined in module.config.php.
     *
     * @param string $source Configuration path to use as source
     * @param string $target Target module in which to create new service
     *
     * @return bool
     * @throws \Exception
     */
    public function extendService($source, $target)
    {
        $parts = explode('/', $source);
        $partCount = count($parts);
        if ($partCount < 3) {
            throw new \Exception('Config path too short.');
        }
        $sourceType = $parts[$partCount - 2];

        $supportedTypes = ['factories', 'invokables'];
        if (!in_array($sourceType, $supportedTypes)) {
            throw new \Exception(
                'Unsupported service type; supported values: '
                . implode(', ', $supportedTypes)
            );
        }

        $config = $this->retrieveConfig($parts);
        if (!$config) {
            throw new \Exception("{$source} not found in configuration.");
        }

        switch ($sourceType) {
        case 'factories':
            $newConfig = $this->cloneFactory($config, $target);
            break;
        case 'invokables':
            $newConfig = $this->createSubclassInModule($config, $target);
            break;
        default:
            throw new \Exception('Reached unreachable code!');
        }
        $this->writeNewConfig($parts, $newConfig, $target);
        return true;
    }

    /**
     * Create a new subclass and factory to override a factory-generated
     * service.
     *
     * @param mixed  $factory Factory configuration for class to extend
     * @param string $module  Module in which to create the new factory
     *
     * @return string
     * @throws \Exception
     */
    protected function cloneFactory($factory, $module)
    {
        // Make sure we can figure out how to handle the factory; it should
        // either be a [controller, method] array or a "controller::method"
        // string; anything else will cause a problem.
        $parts = is_string($factory) ? explode('::', $factory) : $factory;
        if (!is_array($parts) || count($parts) != 2 || !class_exists($parts[0])
            || !method_exists($parts[0], $parts[1])
        ) {
            throw new \Exception('Unexpected factory configuration format.');
        }
        list($factoryClass, $factoryMethod) = $parts;
        $newFactoryClass = $this->generateLocalClassName($factoryClass, $module);
        if (!class_exists($newFactoryClass)) {
            $this->createClassInModule($newFactoryClass, $module);
            $skipBackup = true;
        } else {
            $skipBackup = false;
        }
        if (method_exists($newFactoryClass, $factoryMethod)) {
            throw new \Exception("$newFactoryClass::$factoryMethod already exists.");
        }

        $oldReflection = new ClassReflection($factoryClass);
        $newReflection = new ClassReflection($newFactoryClass);

        $generator = ClassGenerator::fromReflection($newReflection);
        $method = MethodGenerator::fromReflection(
            $oldReflection->getMethod($factoryMethod)
        );
        $this->createServiceClassAndUpdateFactory(
            $method, $oldReflection->getNamespaceName(), $module
        );
        $generator->addMethodFromGenerator($method);
        $this->writeClass($generator, $module, true, $skipBackup);

        return $newFactoryClass . '::' . $factoryMethod;
    }

    /**
     * Given a factory method, extend the class being constructed and create
     * a new factory for the subclass.
     *
     * @param MethodGenerator $method Method to modify
     * @param string          $ns     Namespace of old factory
     * @param string          $module Module in which to make changes
     *
     * @return void
     * @throws \Exception
     */
    protected function createServiceClassAndUpdateFactory(MethodGenerator $method,
        $ns, $module
    ) {
        $body = $method->getBody();
        $regex = '/new\s+([\w\\\\]*)\s*\(/m';
        preg_match_all($regex, $body, $matches);
        $classNames = $matches[1];
        $count = count($classNames);
        if ($count != 1) {
            throw new \Exception("Found $count class names; expected 1.");
        }
        $className = $classNames[0];
        // Figure out fully qualified name for purposes of createSubclassInModule():
        $fqClassName = (substr($className, 0, 1) != '\\')
            ? "$ns\\$className" : $className;
        $newClass = $this->createSubclassInModule($fqClassName, $module);
        $body = preg_replace(
            '/new\s+' . addslashes($className) . '\s*\(/m',
            'new \\' . $newClass . '(',
            $body
        );
        $method->setBody($body);
    }

    /**
     * Determine the name of a local replacement class within the specified
     * module.
     *
     * @param string $class  Name of class to extend/replace
     * @param string $module Module in which to create the new class
     *
     * @return string
     * @throws \Exception
     */
    protected function generateLocalClassName($class, $module)
    {
        // Determine the name of the new class by exploding the old class and
        // replacing the namespace:
        $parts = explode('\\', trim($class, '\\'));
        if (count($parts) < 2) {
            throw new \Exception('Expected a namespaced class; found ' . $class);
        }
        $parts[0] = $module;
        return implode('\\', $parts);
    }

    /**
     * Extend a specified class within a specified module. Return the name of
     * the new subclass.
     *
     * @param string $class  Name of class to create
     * @param string $module Module in which to create the new class
     * @param string $parent Parent class (null for no parent)
     *
     * @return void
     * @throws \Exception
     */
    protected function createClassInModule($class, $module, $parent = null)
    {
        $generator = new ClassGenerator($class, null, null, $parent);
        return $this->writeClass($generator, $module);
    }

    /**
     * Write a class to disk.
     *
     * @param ClassGenerator $classGenerator Representation of class to write
     * @param string         $module         Module in which to write class
     * @param bool           $allowOverwrite Allow overwrite of existing file?
     * @param bool           $skipBackup     Should we skip backing up the file?
     *
     * @return void
     * @throws \Exception
     */
    protected function writeClass(ClassGenerator $classGenerator, $module,
        $allowOverwrite = false, $skipBackup = false
    ) {
        // Use the class name parts from the previous step to determine a path
        // and filename, then create the new path.
        $parts = explode('\\', $classGenerator->getNamespaceName());
        array_unshift($parts, 'module', $module, 'src');
        $this->createTree($parts);

        // Generate the new class:
        $generator = FileGenerator::fromArray(['classes' => [$classGenerator]]);
        $filename = $classGenerator->getName() . '.php';
        $fullPath = APPLICATION_PATH . '/' . implode('/', $parts) . '/' . $filename;
        if (file_exists($fullPath)) {
            if ($allowOverwrite) {
                if (!$skipBackup) {
                    $this->backUpFile($fullPath);
                }
            } else {
                throw new \Exception("$fullPath already exists.");
            }
        }
        if (!file_put_contents($fullPath, $generator->generate())) {
            throw new \Exception("Problem writing to $fullPath.");
        }
        Console::writeLine("Saved file: $fullPath");
    }

    /**
     * Extend a specified class within a specified module. Return the name of
     * the new subclass.
     *
     * @param string $class  Name of class to extend
     * @param string $module Module in which to create the new class
     *
     * @return string
     * @throws \Exception
     */
    protected function createSubclassInModule($class, $module)
    {
        // Normalize leading backslashes; in some contexts we will
        // have them and in others we may not.
        $class = trim($class, '\\');
        $newClass = $this->generateLocalClassName($class, $module);
        $this->createClassInModule($newClass, $module, "\\$class");
        return $newClass;
    }

    /**
     * Create a directory tree.
     *
     * @param array $path Array of subdirectories to create relative to
     * APPLICATION_PATH
     *
     * @return void
     * @throws \Exception
     */
    protected function createTree($path)
    {
        $fullPath = APPLICATION_PATH;
        foreach ($path as $part) {
            $fullPath .= '/' . $part;
            if (!file_exists($fullPath)) {
                if (!mkdir($fullPath)) {
                    throw new \Exception("Problem creating $fullPath");
                }
            }
            if (!is_dir($fullPath)) {
                throw new \Exception("$fullPath is not a directory!");
            }
        }
    }

    /**
     * Create a backup of a file.
     *
     * @param string $filename File to back up
     *
     * @return void
     * @throws \Exception
     */
    public function backUpFile($filename)
    {
        $backup = $filename . '.' . time() . '.bak';
        if (!copy($filename, $backup)) {
            throw new \Exception("Problem generating backup file: $backup");
        }
        Console::writeLine("Created backup: $backup");
    }

    /**
     * Get the path to the module configuration; throw an exception if it is
     * missing.
     *
     * @param string $module Module name
     *
     * @return string
     * @throws \Exception
     */
    public function getModuleConfigPath($module)
    {
        $configPath = APPLICATION_PATH . "/module/$module/config/module.config.php";
        if (!file_exists($configPath)) {
            throw new \Exception("Cannot find $configPath");
        }
        return $configPath;
    }

    /**
     * Write a module configuration.
     *
     * @param string $configPath Path to write to
     * @param string $config     Configuration array to write
     *
     * @return void
     * @throws \Exception
     */
    public function writeModuleConfig($configPath, $config)
    {
        $generator = FileGenerator::fromArray(
            [
                'body' => 'return ' . var_export($config, true) . ';'
            ]
        );
        if (!file_put_contents($configPath, $generator->generate())) {
            throw new \Exception("Cannot write to $configPath");
        }
        Console::writeLine("Successfully updated $configPath");
    }

    /**
     * Update the configuration of a target module.
     *
     * @param array  $path    Representation of path in config array
     * @param string $setting New setting to write into config
     * @param string $module  Module in which to write the configuration
     * @param bool   $backup  Should we back up the existing config?
     *
     * @return void
     * @throws \Exception
     */
    protected function writeNewConfig($path, $setting, $module, $backup  = true)
    {
        // Create backup of configuration
        $configPath = $this->getModuleConfigPath($module);
        if ($backup) {
            $this->backUpFile($configPath);
        }

        $config = include $configPath;
        $current = & $config;
        $finalStep = array_pop($path);
        foreach ($path as $step) {
            if (!is_array($current)) {
                throw new \Exception('Unexpected non-array: ' . $current);
            }
            if (!isset($current[$step])) {
                $current[$step] = [];
            }
            $current = & $current[$step];
        }
        if (!is_array($current)) {
            throw new \Exception('Unexpected non-array: ' . $current);
        }
        $current[$finalStep] = $setting;

        // Write updated configuration
        $this->writeModuleConfig($configPath, $config);
    }

    /**
     * Retrieve a value from the application configuration (or return false
     * if the path is not found).
     *
     * @param array $path Path to walk through configuration
     *
     * @return mixed
     */
    protected function retrieveConfig(array $path)
    {
        $config = $this->config;
        foreach ($path as $part) {
            if (!isset($config[$part])) {
                return false;
            }
            $config = $config[$part];
        }
        return $config;
    }
}