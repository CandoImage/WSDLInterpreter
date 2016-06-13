<?php
/**
 * Interprets WSDL documents for the purposes of PHP 5 object creation
 * 
 * The WSDLInterpreter package is used for the interpretation of a WSDL 
 * document into PHP classes that represent the messages using inheritance
 * and typing as defined by the WSDL rather than SoapClient's limited
 * interpretation.  PHP classes are also created for each service that
 * represent the methods with any appropriate overloading and strict
 * variable type checking as defined by the WSDL.
 *
 * PHP version 5 
 * 
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category    WebServices 
 * @package     WSDLInterpreter  
 * @author      Kevin Vaughan kevin@kevinvaughan.com
 * @copyright   2007 Kevin Vaughan
 * @license     http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 * 
 */

/**
 * A lightweight wrapper of Exception to provide basic package specific 
 * unrecoverable program states.
 * 
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreterException extends Exception { } 

/**
 * The main class for handling WSDL interpretation
 * 
 * The WSDLInterpreter is utilized for the parsing of a WSDL document for rapid
 * and flexible use within the context of PHP 5 scripts.
 * 
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreter 
{
    /**
     * The WSDL document's URI
     * @var string
     * @access private
     */
    private $_wsdl = null;

    /**
     * A SoapClient for loading the WSDL
     * @var SoapClient
     * @access private
     */
    private $_client = null;
    
    /**
     * DOM document representation of the wsdl and its translation
     * @var DOMDocument
     * @access private
     */
    private $_dom = null;
    
    /**
     * Array of classes and members representing the WSDL message types
     * @var array
     * @access private
     */
    private $_classmap = array();
    
    /**
     * Array of sources for WSDL message classes
     * @var array
     * @access private
     */
    private $_classPHPSources = array();
    
    /**
     * Array of sources for WSDL services
     * @var array
     * @access private
     */
    private $_servicePHPSources = array();

    /**
     * Namespace for the output.
     * @var array
     * @access private
     */
    private $_OutputNamespace;

    /**
     * Output indent.
     * @var string
     * @access private
     */
    private $_OutputIndent = "\t";

    /**
     * All in one single file.
     */
    const AUTOLOADER_SINGLE_FILE = 'SINGLE_FILE';
    /**
     * Custom structure.
     */
    const AUTOLOADER_WSDLI = 'WSDLI';
    /**
     * PSR4 according to http://www.php-fig.org/psr/psr-0/
     */
    const AUTOLOADER_PSR4 = 'PSR4';

    /**
     * Defines the autoloader structure to use for the output.
     * @var string
     */
    private $_outputAutoloaderStructure = self::AUTOLOADER_PSR4;

    /**
     * If enabled the arguments for the methods are explicitly defined.
     * @var bool
     */
    private $_outputExpandMethodArguments = false;

    /**
     * If enabled the method arguments aren't checked.
     *
     * It's recommended to use this if you use ExpandMethodArguments as this
     * will check complex types automatically.
     *
     * @var bool
     */
    private $_outputSkipArgumentCheck = false;

    /**
     * Parses the target wsdl and loads the interpretation into object members
     * 
     * @param string $wsdl  the URI of the wsdl to interpret
     * @param string $namespace  the Namespace for the output. NULL will disable the
     *                      namespace.
     * @param string $autoloader  the autoloader structure for the output.
     * @throws WSDLInterpreterException Container for all WSDL interpretation problems
     * @todo Create plug in model to handle extendability of WSDL files
     */
    public function __construct($wsdl, $namespace = 'WSDLI', $autoloader = self::AUTOLOADER_PSR4)
    {
        try {
            $this->_wsdl = $wsdl;
            $this->setOutputNamespace($namespace);
            $this->_outputAutoloaderStructure = $autoloader;
            $this->_client = new SoapClient($this->_wsdl);
            
            $this->_dom = new DOMDocument();
            $this->_dom->load($this->_wsdl, LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
            
            $xpath = new DOMXPath($this->_dom);
            
            /**
             * wsdl:import
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://schemas.xmlsoap.org/wsdl/']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $wsdl = new DOMDocument();
                $wsdl->load($entry->getAttribute("location"), LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                foreach ($wsdl->documentElement->childNodes as $node) {
                    $newNode = $this->_dom->importNode($node, true);
                    $parent->insertBefore($newNode, $entry);
                }
                $parent->removeChild($entry);
            }
            
            /**
             * xsd:import
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://www.w3.org/2001/XMLSchema']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $xsd = new DOMDocument();
                $result = @$xsd->load(dirname($this->_wsdl) . "/" . $entry->getAttribute("schemaLocation"), 
                    LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                if ($result) {
                    foreach ($xsd->documentElement->childNodes as $node) {
                        $newNode = $this->_dom->importNode($node, true);
                        $parent->insertBefore($newNode, $entry);
                    }
                    $parent->removeChild($entry);
                }
            }
            
            
            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error loading WSDL document (".$e->getMessage().")");
        }
        
        try {
            $xsl = new XSLTProcessor();
            $xslDom = new DOMDocument();
            $xslDom->load(dirname(__FILE__)."/wsdl2php.xsl");
            $xsl->registerPHPFunctions();
            $xsl->importStyleSheet($xslDom);
            $this->_dom = $xsl->transformToDoc($this->_dom);
            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error interpreting WSDL document (".$e->getMessage().")");
        }
    }

    /**
     * Set the output namespace.
     *
     * Setting this to NULL will disable the namespace.
     *
     * @param string $wsdl  the Namespace for the output.
     */
    public function setOutputNamespace($namespace) {
      // Remove leading backslash.
      $this->_OutputNamespace = ltrim($namespace, '\\');
    }

    /**
     * Get the output namespace.
     *
     * @return string|NULL the Namespace for the output.
     */
    public function getOutputNamespace() {
      return $this->_OutputNamespace;
    }

    /**
     * Set the output indent.
     *
     * @param string $indent  the indent for the output.
     */
    public function setOutputIndent($indent) {
      $this->_OutputIndent = $indent;
    }

    /**
     * Get the output indent.
     *
     * @return string the indent for the output.
     */
    public function getOutputIndent() {
      return $this->_OutputIndent;
    }

    /**
     * Set the autoloader structure.
     *
     * @param string $autoloader  the autloader structure to use. See class
     *                            constants for available options.
     */
    public function setAutoloader($autoloader) {
      $this->_outputAutoloaderStructure = $autoloader;
    }

    /**
     * Get the autoloader structure.
     *
     * @return string the autloader structure.
     */
    public function getAutoloader() {
      $this->_outputAutoloaderStructure;
    }

    /**
     * Set the expand method arguments flag.
     *
     * If enabled the method arugments are explicitly declared.
     *
     * @param bool $expandMethodArguments
     */
    public function setExpandMethodArguments($expandMethodArguments) {
      $this->_outputExpandMethodArguments = (bool) $expandMethodArguments;
    }

    /**
     * Get the expand method arguments flag.
     *
     * @return bool
     */
    public function getExpandMethodArguments() {
      $this->_outputExpandMethodArguments;
    }

    /**
     * Set the skip argument check flag.
     *
     * If enabled the method arguments aren't checked using the generic
     * inspection.
     * It's recommended to use this if you use ExpandMethodArguments as this
     * will check complex types automatically.
     *
     * @param bool $skipArgumentCheck
     */
    public function setSkipArgumentCheck($skipArgumentCheck) {
      $this->_outputSkipArgumentCheck = (bool) $skipArgumentCheck;
    }

    /**
     * Get the skip argument check flag.
     *
     * @return bool
     */
    public function getSkipArgumentCheck() {
      $this->_outputSkipArgumentCheck;
    }

    /**
     * Validates a name against standard PHP naming conventions
     * 
     * @param string $name the name to validate
     * 
     * @return string the validated version of the submitted name
     * 
     * @access private
     */
    private function _validateNamingConvention($name) 
    {
        return preg_replace('#[^a-zA-Z0-9_\x7f-\xff]*#', '',
            preg_replace('#^[^a-zA-Z_\x7f-\xff]*#', '', $name));
    }
    
    /**
     * Validates a class name against PHP naming conventions and already defined
     * classes, and optionally stores the class as a member of the interpreted classmap.
     * 
     * @param string $className the name of the class to test
     * @param boolean $addToClassMap whether to add this class name to the classmap
     * 
     * @return string the validated version of the submitted class name
     * 
     * @access private
     * @todo Add reserved keyword checks
     */
    private function _validateClassName($className, $addToClassMap = true) 
    {
        $validClassName = $this->_validateNamingConvention($className);
        
        if (class_exists($validClassName)) {
            throw new Exception("Class ".$validClassName." already defined.".
                " Cannot redefine class with class loaded.");
        }
        
        if ($addToClassMap) {
            $this->_classmap[$className] = $validClassName;
        }
        
        return $validClassName;
    }

    
    /**
     * Validates a wsdl type against known PHP primitive types, or otherwise
     * validates the namespace of the type to PHP naming conventions
     * 
     * @param string $type the type to test
     * 
     * @return string the validated version of the submitted type
     * 
     * @access private
     * @todo Extend type handling to gracefully manage extendability of wsdl definitions, add reserved keyword checking
     */    
    private function _validateType($type) 
    {
        $array = false;
        if (substr($type, -2) == "[]") {
            $array = true;
            $type = substr($type, 0, -2);
        }
        switch (strtolower($type)) {
        case "int": case "integer": case "long": case "byte": case "short":
        case "negativeInteger": case "nonNegativeInteger": 
        case "nonPositiveInteger": case "positiveInteger":
        case "unsignedByte": case "unsignedInt": case "unsignedLong": case "unsignedShort":
            $validType = "integer";
            break;
            
        case "float": case "long": case "double": case "decimal":
            $validType = "double";
            break;
            
        case "string": case "token": case "normalizedString": case "hexBinary":
            $validType = "string";
            break;
            
        default:
            $validType = $this->_validateNamingConvention($type);
            break;
        }
        if ($array) {
            $validType .= "[]";
        }
        return $validType;
    }        
    
    /**
     * Loads classes from the translated wsdl document's message types 
     * 
     * @access private
     */
    private function _loadClasses() 
    {
        $classes = $this->_dom->getElementsByTagName("class");
        foreach ($classes as $class) {
            $class->setAttribute("validatedName", 
                $this->_validateClassName($class->getAttribute("name")));
            $extends = $class->getElementsByTagName("extends");
            if ($extends->length > 0) {
                $extends->item(0)->nodeValue = 
                    $this->_validateClassName($extends->item(0)->nodeValue);
                $classExtension = $extends->item(0)->nodeValue;
            } else {
                $classExtension = false;
            }
            $properties = $class->getElementsByTagName("entry");
            foreach ($properties as $property) {
                $property->setAttribute("validatedName", 
                    $this->_validateNamingConvention($property->getAttribute("name")));
                $property->setAttribute("type", 
                    $this->_validateType($property->getAttribute("type")));
            }
            
            $sources[$class->getAttribute("validatedName")] = array(
                "extends" => $classExtension,
                "source" => $this->_generateClassPHP($class, 'Types')
            );
        }
        
        while (sizeof($sources) > 0)
        {
            $classesLoaded = 0;
            foreach ($sources as $className => $classInfo) {
                if (!$classInfo["extends"] || (isset($this->_classPHPSources[$classInfo["extends"]]))) {
                    $this->_classPHPSources[$className] = $classInfo["source"];
                    unset($sources[$className]);
                    $classesLoaded++;
                }
            }
            if (($classesLoaded == 0) && (sizeof($sources) > 0)) {
                throw new WSDLInterpreterException("Error loading PHP classes: ".join(", ", array_keys($sources)));
            }
        }
    }
    
    /**
     * Generates the PHP code for a WSDL message type class representation
     * 
     * This gets a little bit fancy as the magic methods __get and __set in
     * the generated classes are used for properties that are not named 
     * according to PHP naming conventions (e.g., "MY-VARIABLE").  These
     * variables are set directly by SoapClient within the target class,
     * and could normally be retrieved by $myClass->{"MY-VARIABLE"}.  For
     * convenience, however, this will be available as $myClass->MYVARIABLE.
     * 
     * @param DOMElement $class the interpreted WSDL message type node
     * @param string $subNamespace the subnamespace for this class.
     * @return string the php source code for the message type class
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateClassPHP($class, $subNamespace = '')
    {
        $return = '';
        if (!is_null($this->_OutputNamespace)) {
          $subNamespace = ((strpos($subNamespace, '\\') !== 0) ? '\\' : '') . $subNamespace;
          $return .= 'namespace ' . $this->_OutputNamespace . $subNamespace . ';' . "\n\n";
        }
        $return .= '/**'."\n";
        $return .= ' * '.$class->getAttribute("validatedName")."\n";
        $return .= ' */'."\n";
        $return .= "class ".$class->getAttribute("validatedName");
        $extends = $class->getElementsByTagName("extends");
        if ($extends->length > 0) {
            $return .= " extends ".$extends->item(0)->nodeValue;
        }
        $return .= " {\n";
    
        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
            $return .= $this->_OutputIndent . "/**\n"
                     . $this->_OutputIndent . " * @var ".$property->getAttribute("type")."\n"
                     . $this->_OutputIndent . " */\n"
                     . $this->_OutputIndent .'public $'.$property->getAttribute("validatedName").";\n\n";
        }
    
        $extraParams = false;
        $paramMapReturn = $this->_OutputIndent .'private $_parameterMap = array ('."\n";
        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
            if ($property->getAttribute("name") != $property->getAttribute("validatedName")) {
                $extraParams = true;
                $paramMapReturn .= $this->_OutputIndent . $this->_OutputIndent . '"'.$property->getAttribute("name").
                    '" => "'.$property->getAttribute("validatedName").'",'."\n";
            }
        }
        $paramMapReturn .= $this->_OutputIndent .');'."\n\n";
        $paramMapReturn .= $this->_OutputIndent .'/**'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * Provided for setting non-php-standard named variables'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * @param $var Variable name to set'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * @param $value Value to set'."\n";
        $paramMapReturn .= $this->_OutputIndent .' */'."\n";
        $paramMapReturn .= $this->_OutputIndent .'public function __set($var, $value) '.
            '{ $this->{$this->_parameterMap[$var]} = $value; }'."\n";
        $paramMapReturn .= $this->_OutputIndent .'/**'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * Provided for getting non-php-standard named variables'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * @param $var Variable name to get'."\n";
        $paramMapReturn .= $this->_OutputIndent .' * @return mixed Variable value'."\n";
        $paramMapReturn .= $this->_OutputIndent .' */'."\n";
        $paramMapReturn .= $this->_OutputIndent .'public function __get($var) '.
            '{ return $this->{$this->_parameterMap[$var]}; }'."\n";
        
        if ($extraParams) {
            $return .= $paramMapReturn;
        }
    
        $return .= "}\n";
        return $return;
    }
    
    /**
     * Loads services from the translated wsdl document
     * 
     * @access private
     */
    private function _loadServices() 
    {
        $services = $this->_dom->getElementsByTagName("service");
        foreach ($services as $service) {
            $service->setAttribute("validatedName", 
                $this->_validateClassName($service->getAttribute("name"), false));
            $functions = $service->getElementsByTagName("function");
            foreach ($functions as $function) {
                $function->setAttribute("validatedName", 
                    $this->_validateNamingConvention($function->getAttribute("name")));
                $parameters = $function->getElementsByTagName("parameters");
                if ($parameters->length > 0) {
                    $parameterList = $parameters->item(0)->getElementsByTagName("entry");
                    foreach ($parameterList as $variable) {
                        $variable->setAttribute("validatedName", 
                            $this->_validateNamingConvention($variable->getAttribute("name")));
                        $variable->setAttribute("type", 
                            $this->_validateType($variable->getAttribute("type")));
                    }
                }
            }
            
            $this->_servicePHPSources[$service->getAttribute("validatedName")] = 
                $this->_generateServicePHP($service);
        }
    }
    
    /**
     * Generates the PHP code for a WSDL service class representation
     * 
     * This method, in combination with generateServiceFunctionPHP, create a PHP class
     * representation capable of handling overloaded methods with strict parameter
     * type checking.
     * 
     * @param DOMElement $service the interpreted WSDL service node
     * @return string the php source code for the service class
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateServicePHP($service)
    {
        $return = '';
        if (!is_null($this->_OutputNamespace)) {
          $return .= 'namespace ' . $this->_OutputNamespace . ';' . "\n\n";
        }
        $return .= '/**'."\n";
        $return .= ' * '.$service->getAttribute("validatedName")."\n";
        $return .= ' * @author WSDLInterpreter'."\n";
        $return .= ' */'."\n";
        $return .= "class ".$service->getAttribute("validatedName")." extends \SoapClient {\n";

        if (sizeof($this->_classmap) > 0 && !$this->_outputSkipArgumentCheck) {
            $namespace = '';
            if (!is_null($this->_OutputNamespace)) {
              $namespace = $this->_OutputNamespace . '\\\\Types\\\\';
            }
            $return .= $this->_OutputIndent .'/**'."\n";
            $return .= $this->_OutputIndent .' * Default class map for wsdl=>php'."\n";
            $return .= $this->_OutputIndent .' * @access private'."\n";
            $return .= $this->_OutputIndent .' * @var array'."\n";
            $return .= $this->_OutputIndent .' */'."\n";
            $return .= $this->_OutputIndent .'private static $classmap = array('."\n";
            foreach ($this->_classmap as $className => $validClassName)    {
                $return .= $this->_OutputIndent . $this->_OutputIndent . '"'.$className.'" => "'.$namespace.$validClassName.'",'."\n";
            }
            $return .= $this->_OutputIndent . ");\n\n";
        }
        
        $return .= $this->_OutputIndent .'/**'."\n";
        $return .= $this->_OutputIndent .' * Constructor using wsdl location and options array'."\n";
        $return .= $this->_OutputIndent .' * @param string $wsdl WSDL location for this service'."\n";
        $return .= $this->_OutputIndent .' * @param array $options Options for the SoapClient'."\n";
        $return .= $this->_OutputIndent .' */'."\n";
        $return .= $this->_OutputIndent .'public function __construct($wsdl="'.
            $this->_wsdl.'", $options=array()) {'."\n";
        if (!$this->_outputSkipArgumentCheck) {
            $return .= $this->_OutputIndent . $this->_OutputIndent . 'foreach(self::$classmap as $wsdlClassName => $phpClassName) {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . 'if(!isset($options[\'classmap\'][$wsdlClassName])) {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '$options[\'classmap\'][$wsdlClassName] = "\\\".__NAMESPACE__."\\\$phpClassName";' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '}' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . '}' . "\n";
        }
        $return .= $this->_OutputIndent . $this->_OutputIndent . 'parent::__construct($wsdl, $options);'."\n";
        $return .= $this->_OutputIndent . "}\n\n";
        if (!$this->_outputSkipArgumentCheck) {
            $return .= $this->_OutputIndent . '/**' . "\n";
            $return .= $this->_OutputIndent . ' * Checks if an argument list matches against a valid ' .
              'argument type list' . "\n";
            $return .= $this->_OutputIndent . ' * @param array $arguments The argument list to check' . "\n";
            $return .= $this->_OutputIndent . ' * @param array $validParameters A list of valid argument ' .
              'types' . "\n";
            $return .= $this->_OutputIndent . ' * @return boolean true if arguments match against ' .
              'validParameters' . "\n";
            $return .= $this->_OutputIndent . ' * @throws \Exception invalid function signature message' . "\n";
            $return .= $this->_OutputIndent . ' */' . "\n";
            $return .= $this->_OutputIndent . 'public function _checkArguments($arguments, $validParameters) {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . '$variables = "";' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . 'foreach ($arguments as $arg) {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '$type = gettype($arg);' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . 'if ($type == "object") {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '$type = preg_replace(\'/^\'.__NAMESPACE__.\'\\\\\/\', \'\', get_class($arg));' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '}' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . '$variables .= "(".$type.")";' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . '}' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . 'if (!in_array($variables, $validParameters)) {' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent . 'throw new \Exception("Invalid parameter types: ' .
              '".str_replace(")(", ", ", $variables));' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . '}' . "\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . 'return true;' . "\n";
            $return .= $this->_OutputIndent . "}\n\n";
        }
        $functionMap = array();        
        $functions = $service->getElementsByTagName("function");
        foreach ($functions as $function) {
            if (!isset($functionMap[$function->getAttribute("validatedName")])) {
                $functionMap[$function->getAttribute("validatedName")] = array();
            }
            $functionMap[$function->getAttribute("validatedName")][] = $function;
        }    
        foreach ($functionMap as $functionName => $functionNodeList) {
            $return .= $this->_generateServiceFunctionPHP($functionName, $functionNodeList)."\n\n";
        }
    
        $return .= "}";
        return $return;
    }

    /**
     * Generates the PHP code for a WSDL service operation function representation
     * 
     * The function code that is generated examines the arguments that are passed and
     * performs strict type checking against valid argument combinations for the given
     * function name, to allow for overloading.
     * 
     * @param string $functionName the php function name
     * @param array $functionNodeList array of DOMElement interpreted WSDL function nodes
     * @return string the php source code for the function
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */    
    private function _generateServiceFunctionPHP($functionName, $functionNodeList) 
    {
        $namespace = '';
        if (!is_null($this->_OutputNamespace)) {
          $namespace = '\\' . $this->_OutputNamespace . '\\Types\\';
        }
        $return = "";
        $return .= $this->_OutputIndent .'/**'."\n";
        $return .= $this->_OutputIndent .' * Service Call: '.$functionName."\n";
        $return .= $this->_OutputIndent ." *\n";
        $parameterComments = array();
        $parameterNames = array();
        $parameterRawTypes = array();
        $variableTypeOptions = array();
        $returnOptions = array();
        foreach ($functionNodeList as $functionNode) {
            $parameters = $functionNode->getElementsByTagName("parameters");
            if ($parameters->length > 0) {
                $parameters = $parameters->item(0)->getElementsByTagName("entry");
                $parameterTypes = "";
                $parameterList = array();
                foreach ($parameters as $parameter) {
                    $parameterName = $parameter->getAttribute("validatedName");
                    $parameterNames[] = $parameterName;
                    if (substr($parameter->getAttribute("type"), 0, -2) == "[]") {
                        $parameterRawTypes[] = 'array';
                        $parameterTypes .= "(array)";
                    } else {
                        $parameterRawTypes[] = $parameter->getAttribute("type");
                        $parameterTypes .= "(".$parameter->getAttribute("type").")";
                    }
                    $parameterList[] = "(".$parameter->getAttribute("type").") ".
                        $parameterName;
                }
                if (sizeof($parameterList) > 0) {
                    $variableTypeOptions[] = $parameterTypes;
                    $parameterComments[] = $this->_OutputIndent .' * '.join(", ", $parameterList);
                }
            }
            $returns = $functionNode->getElementsByTagName("returns");
            if ($returns->length > 0) {
                $returns = $returns->item(0)->getElementsByTagName("entry");
                if ($returns->length > 0) {
                    $returnOption = $returns->item(0)->getAttribute("type");
                    // If the type is in the class map add a data typing to the arg.
                    if (isset($this->_classmap[$returnOption])) {
                      $returnOption = $namespace . $returnOption;
                    }
                    $returnOptions[] = $returnOption;
                }
            }
        }

        if (!$this->_outputExpandMethodArguments) {
          $func_params = '$mixed = null';
          $return .= $this->_OutputIndent .' * Parameter options:'."\n";
          $return .= join("\n", $parameterComments)."\n";
          $return .= $this->_OutputIndent . ' * @param mixed,... See function description for parameter options' . "\n";
        }
        else {
          $methodParameters = array();
          foreach ($parameterNames as $k => $v) {
            $methodParameters[$k] = '$' . $v;
            $type = $parameterRawTypes[$k];
            // If the type is in the class map add a data typing to the arg.
            if (isset($this->_classmap[$type])) {
              $type = $namespace . $type;
              $methodParameters[$k] = $type . ' ' . $methodParameters[$k];
            }
            $return .= $this->_OutputIndent .' * @param ' . $type . ' $' . $v ."\n";
          }
          $func_params = implode(', ', $methodParameters);
        }
        $return .= $this->_OutputIndent ." *\n";
        $return .= $this->_OutputIndent .' * @return '.join("|", array_unique($returnOptions))."\n";
        $return .= $this->_OutputIndent ." *\n";
        $return .= $this->_OutputIndent .' * @throws \Exception invalid function signature message'."\n";
        $return .= $this->_OutputIndent .' */'."\n";
        $return .= $this->_OutputIndent .'public function '.$functionName.'(' . $func_params . ') {'."\n";
        $return .= $this->_OutputIndent . $this->_OutputIndent . '$args = func_get_args();'."\n";
        if (!$this->_outputSkipArgumentCheck) {
            $return .= $this->_OutputIndent . $this->_OutputIndent . '$validParameters = array('."\n";
            foreach ($variableTypeOptions as $variableTypeOption) {
                $return .= $this->_OutputIndent . $this->_OutputIndent . $this->_OutputIndent .'"'.$variableTypeOption.'",'."\n";
            }
            $return .= $this->_OutputIndent . $this->_OutputIndent . ');'."\n";
            $return .= $this->_OutputIndent . $this->_OutputIndent . '$this->_checkArguments($args, $validParameters);' . "\n";
        }
        $return .= $this->_OutputIndent . $this->_OutputIndent . 'return $this->__soapCall("'.
            $functionNodeList[0]->getAttribute("name").'", $args);'."\n";
        $return .= $this->_OutputIndent .'}'."\n";
        
        return $return;
    }
    
    /**
     * Saves the PHP source code that has been loaded to a target directory.
     * 
     * Services will be saved by their validated name, and classes will be included
     * with each service file so that they can be utilized independently.
     * 
     * @param string $outputDirectory the destination directory for the source code
     * @return array array of source code files that were written out
     * @throws WSDLInterpreterException problem in writing out service sources
     * @access public
     * @todo Add split file options for more efficient output
     */
    public function savePHP($outputDirectory) 
    {
        $this->_loadClasses();
        $this->_loadServices();

        if (sizeof($this->_servicePHPSources) == 0) {
            throw new WSDLInterpreterException("No services loaded");
        }

        switch($this->_outputAutoloaderStructure) {
          case self::AUTOLOADER_WSDLI:
          default:
            $outputFiles = $this->_savePHP_WSDLI($outputDirectory);
            break;

          case self::AUTOLOADER_PSR4:
            $outputFiles = $this->_savePHP_PSR4($outputDirectory);
            break;

          case self::AUTOLOADER_SINGLE_FILE:
            $outputFiles = $this->_savePHP_SingleFile($outputDirectory);
            break;
        }
        
        return $outputFiles;
    }

    protected function _savePHP_WSDLI ($outputDirectory) {
      $outputDirectory = rtrim($outputDirectory,"/");

      $outputFiles = array();

      if(!is_dir($outputDirectory."/")) {
        mkdir($outputDirectory."/");
      }

      if(!is_dir($outputDirectory."/classes/")) {
        mkdir($outputDirectory."/classes/");
      }

      foreach($this->_classPHPSources as $className => $classCode) {
        $filename = $outputDirectory."/classes/".$className.".class.php";
        if (file_put_contents($filename, "<?php\n\n".$classCode)) {
          $outputFiles[] = $filename;
        }
      }

      foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
        $filename = $outputDirectory."/".$serviceName.".php";
        if (file_put_contents($filename, "<?php\n\n".$serviceCode)) {
          $outputFiles[] = $filename;
        }
      }

      if (sizeof($outputFiles) == 0) {
        throw new WSDLInterpreterException("Error writing PHP source files.");
      }
      return $outputFiles;
    }

    protected function _savePHP_PSR4 ($outputDirectory) {
      $outputDirectory = rtrim($outputDirectory,"/");

      $outputFiles = array();

      if(!is_dir($outputDirectory . "/")) {
        mkdir($outputDirectory . "/");
      }

      foreach($this->_classPHPSources as $className => $classCode) {

        if(!is_dir($outputDirectory . "/Types/")) {
          mkdir($outputDirectory . "/Types/");
        }

        $filename = $outputDirectory . "/Types/" . $className . ".php";
        if (file_put_contents($filename, "<?php\n\n" . $classCode)) {
          $outputFiles[] = $filename;
        }
      }

      foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
        $filename = $outputDirectory . "/" . $serviceName . ".php";
        if (file_put_contents($filename, "<?php\n\n" . $serviceCode)) {
          $outputFiles[] = $filename;
        }
      }

      if (sizeof($outputFiles) == 0) {
        throw new WSDLInterpreterException("Error writing PHP source files.");
      }
      return $outputFiles;
    }

    protected function _savePHP_SingleFile($outputDirectory) {
      $outputDirectory = rtrim($outputDirectory,"/");

      $outputFiles = array();

      if(!is_dir($outputDirectory."/")) {
        mkdir($outputDirectory."/");
      }

      $name = parse_url($this->_wsdl, PHP_URL_HOST);
      $filename = $outputDirectory . "/" . $name . ".php";
      $outputFiles[] = $filename;
      file_put_contents($filename, "<?php\n\n");

      foreach($this->_classPHPSources as $className => $classCode) {
        file_put_contents($filename, $classCode, FILE_APPEND);
      }

      foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
        file_put_contents($filename, $serviceCode, FILE_APPEND);
      }

      if (sizeof($outputFiles) == 0) {
        throw new WSDLInterpreterException("Error writing PHP source files.");
      }
      return $outputFiles;
    }
}
?>
