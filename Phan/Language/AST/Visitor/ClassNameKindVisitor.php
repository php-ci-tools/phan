<?php declare(strict_types=1);
namespace Phan\Language\AST\Visitor;

use \Phan\Debug;
use \Phan\Language\AST\Element;
use \Phan\Language\AST\KindVisitorImplementation;
use \Phan\Language\Context;
use \Phan\Language\UnionType;
use \Phan\Log;
use \ast\Node;

/**
 * A visitor that can extract a class name from a few
 * types of nodes
 */
class ClassNameKindVisitor extends KindVisitorImplementation {
    use \Phan\Language\AST;

    /**
     * @var $context
     * The context of the current execution
     */
    private $context;

    /**
     * @param Context $context
     * The context of the current execution
     */
    public function __construct(Context $context) {
        $this->context = $context;
    }

    /**
     * Default visitor for node kinds that do not have
     * an overriding method
     */
    public function visit(Node $node) : string {
        return '';
    }

    public function visitNew(Node $node) : string {

        // TODO: What do we do with calls of the form
        //       `$foo::method()`;
        if ($node->children['class']->kind == \ast\AST_VAR) {
            return '';
        }

        // TODO: Not sure what types these'd be
        if($node->children['class']->kind !== \ast\AST_NAME) {
            assert(false,
                "Unhandled case in {$this->context}");
            return '';
        }

        $class_name =
            $node->children['class']->children['name'];

        if(!in_array($class_name, ['self', 'static', 'parent'])) {
            return self::astQualifiedName(
                $this->context,
                $node->children['class']
            );
        }

        if (!$this->context->isClassScope()) {
            Log::err(
                Log::ESTATIC,
                "Cannot access {$class_name}:: when no class scope is active",
                $this->context->getFile(),
                $node->lineno
            );

            return '';
        }

        if($class_name == 'static') {
            return (string)$this->context->getClassFQSEN()
                ->getClassName();
        }

        if($class_name == 'self') {
            // TODO
            if ($this->context->isGlobalScope()) {
                assert(false, "Not Implemented");
                list($class_name,) =
                    explode('::', $current_scope);
            } else {
                return (string)$this->context->getClassFQSEN()
                    ->getClassName();
            }
        }

        if($class_name == 'parent') {
            $clazz =
                $this->context->getClassInScope();

            if (!$clazz->hasParentClassFQSEN()) {
                // TODO: This may be getting called in
                //       the first pass.
                /*
                Log::err(
                    Log::EFATAL,
                    "Call to parent in {$class_name} when no parent exists",
                    $this->context->getFile(),
                    $node->lineno
                );
                */

                return '';
            }

            return (string)$clazz->getParentClassFQSEN();
        }

        return '';
    }

    public function visitStaticCall(Node $node) : string {
        return $this->visitNew($node);
    }

    public function visitClassConst(Node $node) : string {
        return $this->visitNew($node);
    }

    public function visitInstanceOf(Node $node) : string {
        if($node->children[1]->kind == \ast\AST_NAME) {
            return qualified_name($file, $node->children[1], $namespace);
        }

        return '';
    }

    public function visitMethodCall(Node $node) : string {

        if($node->children['expr']->kind == \ast\AST_VAR) {
            if(($node->children['expr']->children['name'] instanceof Node)) {
                // TODO: not sure what to make of this
                return '';
            }

            // $var->method()
            if($node->children['expr']->children['name'] == 'this') {
                if(!$this->context->isClassScope()) {
                    Log::err(
                        Log::ESTATIC,
                        'Using $this when not in object context',
                        $this->context->getFile(),
                        $node->lineno
                    );
                    return '';
                }

                return (string)$this->context->getClassFQSEN();
            }

            $variable_name =
                $node->children['expr']->children['name'];

            if (!$this->context->getScope()->hasVariableWithName(
                $variable_name
            )) {
                // Got lost, couldn't find the variable in the current scope
                // If it really isn't defined, it will be caught by the
                // undefined var error
                return '';
            }

            $variable =
                $this->context->getScope()->getVariableWithName($variable_name);

            // Hack - loop through the possible types of the var and assume
            // first found class is correct
            foreach($variable->getUnionType()->nonGenericTypes() as $type_name) {
                $child_class_fqsen =
                    $this->context->getScopeFQSEN()->withClassName(
                        $this->context,
                        (string)$type_name
                    );

                if ($this->context->getCodeBase()->hasClassWithFQSEN($child_class_fqsen)) {
                    return (string)$this->context->getScopeFQSEN()->withClassName(
                        $this->context,
                        (string)$type_name
                    );
                }
            }

            // Could not find name
            return '';
        }

        if($node->children['expr']->kind == \ast\AST_PROP) {
            $prop = $node->children['expr'];

            if(!($prop->children['expr']->kind == \ast\AST_VAR
                && !($prop->children['expr']->children['name'] instanceof Node))
            ) {
                return '';
            }

            // $var->prop->method()
            $var = $prop->children['expr'];
            if($var->children['name'] == 'this') {

                // If we're not in a class scope, 'this' won't work
                if(!$this->context->isClassScope()) {
                    Log::err(
                        Log::ESTATIC,
                        'Using $this when not in object context',
                        $this->context->getFile(),
                        $node->lineno
                    );

                    return '';
                }

                // Get the class in scope
                $clazz = $this->context->getCodeBase()->getClassByFQSEN(
                    $this->context->getClassFQSEN()
                );

                if($prop->children['prop'] instanceof Node) {
                    // $this->$prop->method() - too dynamic, give up
                    return '';
                }

                $property_name = $prop->children['prop'];

                if ($clazz->hasPropertyWithName($property_name)) {
                    $property =
                        $clazz->getPropertyWithName($property_name);

                    // Find the first viable property type
                    foreach ($property->getUnionType()->nonGenericTypes() as $class_name) {
                        $class_fqsen =
                            $this->context->getScopeFQSEN()->withClassName(
                                $this->context,
                                (string)$class_name
                            );

                        if ($this->context->getCodeBase()->hasClassWithFQSEN($class_fqsen)) {
                            return (string)$class_fqsen;
                        }
                    }
                }

                // No such property was found, or none were classes
                // that could be found
                return '';
            }

            // TODO: Not sure what to make of non 'this'
            //       properties
            return '';
        }

        if ($node->children['expr']->kind == \ast\AST_METHOD_CALL) {
            // Get the type returned by the first method
            // call.
            $union_type = UnionType::fromNode(
                $this->context,
                $node->children['expr']
            );

            // Hope that its a class
            return (string)$union_type;
        }

        return '';
    }

    public function visitProp(Node $node) : string {
        return $this->visitMethodCall($node);
    }

}