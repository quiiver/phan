<?php declare(strict_types=1);
namespace Phan;

use Phan\CodeBase\ClassMap;
use Phan\Language\Element\ClassConstant;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionFactory;
use Phan\Language\Element\GlobalConstant;
use Phan\Language\Element\Method;
use Phan\Language\Element\Property;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassElement;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\UnionType;

/**
 * A CodeBase represents the known state of a code base
 * we're analyzing.
 *
 * In order to understand internal classes, interfaces,
 * traits and functions, a CodeBase needs to be
 * initialized with the list of those elements begotten
 * before any classes are loaded.
 *
 * # Example
 * ```
 * // Grab these before we define our own classes
 * $internal_class_name_list = get_declared_classes();
 * $internal_interface_name_list = get_declared_interfaces();
 * $internal_trait_name_list = get_declared_traits();
 * $internal_function_name_list = get_defined_functions()['internal'];
 *
 * // Load any required code ...
 *
 * $code_base = new CodeBase(
 *     $internal_class_name_list,
 *     $internal_interface_name_list,
 *     $internal_trait_name_list,
 *     $internal_function_name_list
 *  );
 *
 *  // Do stuff ...
 * ```
 */
class CodeBase
{
    /**
     * @var Clazz[]|Map
     * A map from FQSEN to a class
     */
    private $fqsen_class_map;

    /**
     * @var GlobalConstant[]|Map
     * A map from FQSEN to a global constant
     */
    private $fqsen_global_constant_map;

    /**
     * @var Func[]|Map
     * A map from FQSEN to function
     */
    private $fqsen_func_map;

    /**
     * @var Func[]|Method[]|Set
     * The set of all functions and methods
     */
    private $func_and_method_set;

    /**
     * @var ClassMap[]|Map
     * A map from FullyQualifiedClassName to a ClassMap,
     * an object that holds properties, methods and class
     * constants.
     */
    private $class_fqsen_class_map_map;

    /**
     * @var Set[]|Method[][]
     * A map from a string method name to a Set of
     * Methods
     */
    private $name_method_map = [];

    /**
     * @var bool
     * If true, elements will be ensured to be hydrated
     * on demand as they are requested.
     */
    private $should_hydrate_requested_elements = false;

    /**
     * Initialize a new CodeBase
     */
    public function __construct(
        array $internal_class_name_list,
        array $internal_interface_name_list,
        array $internal_trait_name_list,
        array $internal_function_name_list
    ) {
        $this->fqsen_class_map = new Map;
        $this->fqsen_global_constant_map = new Map;
        $this->fqsen_func_map = new Map;
        $this->class_fqsen_class_map_map = new Map;
        $this->func_and_method_set = new Set;

        // Add any pre-defined internal classes, interfaces,
        // traits and functions
        $this->addClassesByNames($internal_class_name_list);
        $this->addClassesByNames($internal_interface_name_list);
        $this->addClassesByNames($internal_trait_name_list);
        $this->addFunctionsByNames($internal_function_name_list);
    }

    /**
     * @return void
     */
    public function setShouldHydrateRequestedElements(
        bool $should_hydrate_requested_elements
    ) {
        $this->should_hydrate_requested_elements =
            $should_hydrate_requested_elements;
    }

    /**
     * @param string[] $class_name_list
     * A list of class names to load type information for
     *
     * @return null
     */
    private function addClassesByNames(array $class_name_list)
    {
        foreach ($class_name_list as $i => $class_name) {
            $this->addClass(Clazz::fromClassName($this, $class_name));
        }
    }

    /**
     * @param string[] $function_name_list
     * A list of function names to load type information for
     */
    private function addFunctionsByNames(array $function_name_list) {
        foreach ($function_name_list as $i => $function_name) {
            foreach (FunctionFactory::functionListFromName($this, $function_name)
                as $function_or_method
            ) {
                if ($function_or_method instanceof Method) {
                    $this->addMethod($function_or_method);
                } else {
                    $this->addFunction($function_or_method);
                }
            }
        }
    }

    /**
     * Clone dependent objects when cloning this object
     */
    public function __clone() {
        $this->fqsen_class_map =
            clone($this->fqsen_class_map);

        $this->fqsen_global_constant_map =
            clone($this->fqsen_global_constant_map);

        $this->fqsen_func_map =
            clone($this->fqsen_func_map);

        $this->class_fqsen_class_map_map =
            clone($this->class_fqsen_class_map_map);

        $this->func_and_method_set =
            clone($this->func_and_method_set);
    }

    /**
     * @param Clazz $class
     * A class to add.
     *
     * @return void
     */
    public function addClass(Clazz $class) {
        // Map the FQSEN to the class
        $this->fqsen_class_map[$class->getFQSEN()] = $class;
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasClassWithFQSEN(
        FullyQualifiedClassName $fqsen
    ) : bool {
        return !empty($this->fqsen_class_map[$fqsen]);
    }

    /**
     * @param FullyQualifiedClassName $fqsen
     * The FQSEN of a class to get
     *
     * @return Clazz
     * A class with the given FQSEN
     */
    public function getClassByFQSEN(
        FullyQualifiedClassName $fqsen
    ) : Clazz {
        $clazz = $this->fqsen_class_map[$fqsen];

        // This is an optimization that saves us a few minutes
        // on very large code bases.
        //
        // Instead of 'hydrating' all classes (expanding their
        // types and importing parent methods, properties, etc.)
        // all in one go, we just do it on the fly as they're
        // requested. When running as multiple processes this
        // lets us avoid a significant amount of hydration per
        // process.
        if ($this->should_hydrate_requested_elements) {
            $clazz->hydrate($this);
        }

        return $clazz;
    }

    /**
     * @return Clazz[]|Map
     * A list of all classes
     */
    public function getClassMap() : Map
    {
        return $this->fqsen_class_map;
    }

    /**
     * @param Method $method
     * A method to add to the code base
     *
     * @return void
     */
    public function addMethod(Method $method) {

        // Add the method to the map
        $this->getClassMapByFQSEN(
            $method->getFQSEN()
        )->addMethod($method);

        $this->func_and_method_set->attach($method);

        // If we're doing dead code detection and this is a
        // method, map the name to the FQSEN so we can do hail-
        // mary references.
        if (Config::get()->dead_code_detection) {
            if (empty($this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()])) {
                $this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()] = new Set;
            }
            $this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()]->attach($method);
        }
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasMethodWithFQSEN(
        FullyQualifiedMethodName $fqsen
    ) : bool {
        return $this->getClassMapByFQSEN($fqsen)->hasMethodWithName(
            $fqsen->getNameWithAlternateId()
        );
    }

    /**
     * @param FullyQualifiedMethodName $fqsen
     * The FQSEN of a method to get
     *
     * @return Method
     * A method with the given FQSEN
     */
    public function getMethodByFQSEN(
        FullyQualifiedMethodName $fqsen
    ) : Method {
        return $this->getClassMapByFQSEN($fqsen)->getMethodByName(
            $fqsen->getNameWithAlternateId()
        );
    }

    /**
     * @return Method[]
     * The set of methods associated with the given class
     */
    public function getMethodMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ) : array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getMethodMap();
    }

    /**
     * @return Method[]|Set
     * A set of all known methods with the given name
     */
    public function getMethodSetByName(string $name) : Set
    {
        assert(Config::get()->dead_code_detection,
            __METHOD__ . ' can only be called when dead code '
            . ' detection is enabled.'
        );

        return $this->name_method_map[$name] ?? new Set;
    }

    /**
     * @return Method[]|Func[]|Set
     * The set of all methods and functions
     */
    public function getFunctionAndMethodSet() : Set
    {
        return $this->func_and_method_set;
    }

    /**
     * @param Func $function
     * A function to add to the code base
     *
     * @return void
     */
    public function addFunction(Func $function)
    {
        // Add it to the map of functions
        $this->fqsen_func_map[$function->getFQSEN()] = $function;

        // Add it to the set of functions and methods
        $this->func_and_method_set->attach($function);
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasFunctionWithFQSEN(
        FullyQualifiedFunctionName $fqsen
    ) : bool {
        $has_function = $this->fqsen_func_map->contains($fqsen);

        if ($has_function) {
            return $has_function;
        }

        // Check to see if this is an internal function that hasn't
        // been loaded yet.
        return $this->hasInternalFunctionWithFQSEN($fqsen);
    }

    /**
     * @param FullyQualifiedFunctionName $fqsen
     * The FQSEN of a function to get
     *
     * @return Func
     * A function with the given FQSEN
     */
    public function getFunctionByFQSEN(
        FullyQualifiedFunctionName $fqsen
    ) : Func {

        if (empty($this->fqsen_func_map[$fqsen])) {
            print "Not found $fqsen\n";
        }

        return $this->fqsen_func_map[$fqsen];
    }

    /**
     * @return Map|Func[]
     */
    public function getFunctionMap() : Map
    {
        return $this->fqsen_func_map;
    }

    /**
     * @param ClassConstant $class_constant
     * A class constant to add to the code base
     *
     * @return void
     */
    public function addClassConstant(ClassConstant $class_constant)
    {
        return $this->getClassMapByFQSEN(
            $class_constant->getFQSEN()
        )->addClassConstant($class_constant);
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasClassConstantWithFQSEN(
        FullyQualifiedClassConstantName $fqsen
    ) : bool {
        return $this->getClassMapByFQSEN(
            $fqsen
        )->hasClassConstantWithName($fqsen->getNameWithAlternateId());
    }

    /**
     * @param FullyQualifiedClassConstantName $fqsen
     * The FQSEN of a class constant to get
     *
     * @return ClassConstant
     * A class constant with the given FQSEN
     */
    public function getClassConstantByFQSEN(
        FullyQualifiedClassConstantName $fqsen
    ) : ClassConstant {
        return $this->getClassMapByFQSEN(
            $fqsen
        )->getClassConstantByName($fqsen->getNameWithAlternateId());
    }

    /**
     * @return ClassConstant[]
     * The set of class constants associated with the given class
     */
    public function getClassConstantMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ) : array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getClassConstantMap();
    }

    /**
     * @param GlobalConstant $global_constant
     * A global constant to add to the code base
     *
     * @return void
     */
    public function addGlobalConstant(GlobalConstant $global_constant) {
        $this->fqsen_global_constant_map[
            $global_constant->getFQSEN()
        ] = $global_constant;
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasGlobalConstantWithFQSEN(
        FullyQualifiedGlobalConstantName $fqsen
    ) : bool {
        return !empty($this->fqsen_global_constant_map[$fqsen]);
    }

    /**
     * @param FullyQualifiedGlobalConstantName $fqsen
     * The FQSEN of a global constant to get
     *
     * @return GlobalConstant
     * A global constant with the given FQSEN
     */
    public function getGlobalConstantByFQSEN(
        FullyQualifiedGlobalConstantName $fqsen
    ) : GlobalConstant {
        return $this->fqsen_global_constant_map[$fqsen];
    }

    /**
     * @return Map|GlobalConstant[]
     */
    public function getGlobalConstantMap() : Map
    {
        return $this->fqsen_global_constant_map;
    }

    /**
     * @param Property $property
     * A property to add to the code base
     *
     * @return void
     */
    public function addProperty(Property $property)
    {
        return $this->getClassMapByFQSEN(
            $property->getFQSEN()
        )->addProperty($property);
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasPropertyWithFQSEN(
        FullyQualifiedPropertyName $fqsen
    ) : bool {
        return $this->getClassMapByFQSEN(
            $fqsen
        )->hasPropertyWithName($fqsen->getNameWithAlternateId());
    }

    /**
     * @param FullyQualifiedPropertyName $fqsen
     * The FQSEN of a property to get
     *
     * @return Property
     * A property with the given FQSEN
     */
    public function getPropertyByFQSEN(
        FullyQualifiedPropertyName $fqsen
    ) : Property {
        return $this->getClassMapByFQSEN(
            $fqsen
        )->getPropertyByName($fqsen->getNameWithAlternateId());
    }

    /**
     * @return Property[]
     * The set of properties associated with the given class
     */
    public function getPropertyMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ) : array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getPropertyMap();
    }

    /**
     * @param FullyQualifiedClassElement $fqsen
     * The FQSEN of a class element
     *
     * @return ClassMap
     * Get the class map for an FQSEN representing
     * a class element
     */
    private function getClassMapByFQSEN(
        FullyQualifiedClassElement $fqsen
    ) : ClassMap {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        );
    }

    /**
     * @param FullyQualifiedClassName $fqsen
     * The FQSEN of a class
     *
     * @return ClassMap
     * Get the class map for an FQSEN representing
     * a class element
     */
    private function getClassMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ) : ClassMap {
        if (empty($this->class_fqsen_class_map_map[$fqsen])) {
            $this->class_fqsen_class_map_map[$fqsen] = new ClassMap;
        }

        return $this->class_fqsen_class_map_map[$fqsen];
    }

    /**
     * @return Map|ClassMap[]
     */
    public function getClassMapMap() : Map
    {
        return $this->class_fqsen_class_map_map;
    }

    /**
     * @param FullyQualifiedFunctionName
     * The FQSEN of a function we'd like to look up
     *
     * @return bool
     * If the FQSEN represents an internal function that
     * hasn't been loaded yet, true is returned.
     */
    private function hasInternalFunctionWithFQSEN(
        FullyQualifiedFunctionName $fqsen
    ) : bool
    {
        // Only root namespaced functions will be found in
        // the internal function map.
        if ($fqsen->getNamespace() != '\\') {
            return false;
        }

        // For elements in the root namespace, check to see if
        // there's a static method signature for something that
        // hasn't been loaded into memory yet and create a
        // method out of it as its requested

        $function_signature_map =
            UnionType::internalFunctionSignatureMap();

        if (!empty($function_signature_map[$fqsen->getNameWithAlternateId()])) {
            $signature = $function_signature_map[$fqsen->getNameWithAlternateId()];

            // Add each method returned for the signature
            foreach (FunctionFactory::functionListFromSignature(
                $this,
                $fqsen,
                $signature
            ) as $i => $function) {
                $this->addFunction($function);
            }

            return true;
        }

        return false;
    }

    /**
     * @return int
     * The total number of elements of all types in the
     * code base.
     */
    public function totalElementCount() : int {
        $sum = (
            count($this->getFunctionMap())
            + count($this->getGlobalConstantMap())
            + count($this->getClassMap())
        );

        foreach ($this->getClassMapMap() as $class_map) {
            $sum += (
                count($class_map->getClassConstantMap())
                + count($class_map->getPropertyMap())
                + count($class_map->getMethodMap())
            );
        }

        return $sum;
    }

    /**
     * @return void;
     */
    public function flushDependenciesForFile(string $file_path)
    {
        // TODO
    }

    /**
     * flushByFQSEN
     *
     * @param FQSEN $fqsen
     * @return void
     */
    public function flusByFQSEN($fqsen) {
        if ($fqsen instanceof FullyQualifiedClassName) {
            unset($this->fqsen_class_map[$fqsen]);
            unset($this->class_fqsen_class_map_map[$fqsen]);
        } else if ($fqsen instanceof FullyQualifiedFunctionName) {
            unset($this->fqsen_func_map[$fqsen]);
        } else if ($fqsen instanceof FullyQualifiedGlobalConstantName) {
            unset($this->fqsen_global_constant_map[$fqsen]);
        }
    }

    /**
     * @return void
     */
    public function store()
    {
        // TODO: ...
    }

    /**
     * @return string[]
     * The list of files that depend on the code in the given
     * file path
     */
    public function dependencyListForFile(string $file_path) : array
    {
        // TODO: ...
        return [];
    }

}
