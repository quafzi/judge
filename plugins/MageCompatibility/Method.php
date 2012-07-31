<?php
namespace MageCompatibility;

class Method
{
    const TYPE_MIXED   = 'mixed';
    const TYPE_UNKNOWN = 'unknown';
    const TYPE_INT     = 'int';
    const TYPE_BOOL    = 'bool';
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_FLOAT   = 'float';
    const TYPE_ARRAY   = 'array';
    const TYPE_STRING  = 'string';

    protected $name;
    protected $params=array();
    protected $context=array();

    public function __construct($name, $params, $context)
    {
        $this->setName($name);
        $this->setParams($params);
        $this->setContext($context);
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function setContext($context)
    {
        if (false == is_array($context)) {
            $context = $this->getContextArray($context);
        }
        $this->context = $context;
    }

    /**
     * validate param type for {$offset}th param
     * !!!will return true if we are not sure about the type!!!
     * 
     * @param int    $offset Param offset
     * @param string $type   Doctype styled type
     * @return bool
     */
    public function isParamType($offset, $expectedType)
    {
        $param = $this->params[$offset];
        $value = $param->value;
        $type  = self::TYPE_MIXED;
        if ($param->value instanceof \PHPParser_Node_Expr_MethodCall) {
            $method = new Method($param->value->name, $param->value->args, null);
            $type = $method->getReturnType();
        } elseif ($param->value instanceof \PHPParser_Node_Scalar_String
            || $param->value instanceof \PHPParser_Node_Scalar_ClassConst
            || $param->value instanceof \PHPParser_Node_Scalar_DirConst
            || $param->value instanceof \PHPParser_Node_Scalar_FileConst
            || $param->value instanceof \PHPParser_Node_Scalar_FuncConst
            || $param->value instanceof \PHPParser_Node_Scalar_LineConst
            || $param->value instanceof \PHPParser_Node_Scalar_MethodConst
            || $param->value instanceof \PHPParser_Node_Scalar_NSConst
            || $param->value instanceof \PHPParser_Node_Scalar_TraitConst
        ) {
            $type = self::TYPE_STRING;
        } elseif ($param->value instanceof \PHPParser_Node_Scalar_DNumber) {
            $type = self::TYPE_FLOAT;
        } elseif ($param->value instanceof \PHPParser_Node_Scalar_LNumber) {
            $type = self::TYPE_INT;
        } elseif ($param->value instanceof \PHPParser_Node_Expr_Array) {
            $type = self::TYPE_FLOAT;
        }
        if (in_array($expectedType, array(self::TYPE_UNKNOWN, self::TYPE_MIXED))) {
            return true;
        }
        return ($type == $expectedType);
    }

    /**
     * examine return type of the method
     * 
     * @return string
     */
    public function getReturnType()
    {
        return self::TYPE_MIXED;
    }

    /**
     * getContextArray 
     * 
     * @TODO
     * @param mixed $contextString 
     * @return void
     */
    protected function getContextArray($contextString)
    {
        return array();
    }

    public function getName()
    {
        return $this->name;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function getContext()
    {
        return $this->context;
    }

    /**
     * if method is used in given context
     * 
     * @TODO
     * @param array $contextTokens
     * @return void
     */
    public function isInContext($contextTokens)
    {
        return true;
    }
}
