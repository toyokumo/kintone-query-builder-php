<?php

namespace KintoneQueryBuilder;

/**
 * Class KintoneQueryExpr
 *
 * This class builds logical condition clauses.
 * Note that you can't specify 'offset' or 'order by' with this class.
 * In that case, you should use KintoneQueryBuilder.
 * KintoneQueryExpr can be a argument of new KintoneQueryBuilder() to build  a nested query like '(A and B) or (C and D)'.
 *
 * @package KintoneQueryBuilder
 *
 */

class KintoneQueryExpr
{
    /**
     * @var KintoneQueryBuffer $buffer
     */
    protected $buffer;

    /**
     * KintoneQueryExpr constructor.
     */
    public function __construct()
    {
        $this->buffer = new KintoneQueryBuffer();
    }

    /**
     * @param string $s
     * @return bool
     */
    private static function funcCheck(string $s): bool
    {
        // https://developer.cybozu.io/hc/ja/articles/202331474-%E3%83%AC%E3%82%B3%E3%83%BC%E3%83%89%E3%81%AE%E5%8F%96%E5%BE%97-GET-
        // "関数"
        $regexs = [
            '/LOGINUSER\(\)/', // LOGINUSER()
            '/PRIMARY_ORGANIZATION\(\)/',
            '/NOW\(\)/',
            '/TODAY\(\)/',
            '/YESTERDAY\(\)/',
            '/TOMORROW\(\)/',
            '/FROM_TODAY\(-?\d+,( )*DAYS\)/',
            '/FROM_TODAY\(-?\d+,( )*WEEKS\)/',
            '/FROM_TODAY\(-?\d+,( )*MONTHS\)/',
            '/FROM_TODAY\(-?\d+,( )*YEARS\)/',
            '/THIS_WEEK\(\)/',
            '/THIS_WEEK\(SUNDAY\)/',
            '/THIS_WEEK\(MONDAY\)/',
            '/THIS_WEEK\(TUESDAY\)/',
            '/THIS_WEEK\(WEDNESDAY\)/',
            '/THIS_WEEK\(THURSDAY\)/',
            '/THIS_WEEK\(FRIDAY\)/',
            '/THIS_WEEK\(SATURDAY\)/',
            '/LAST_WEEK\(\)/',
            '/LAST_WEEK\(SUNDAY\)/',
            '/LAST_WEEK\(MONDAY\)/',
            '/LAST_WEEK\(TUESDAY\)/',
            '/LAST_WEEK\(WEDNESDAY\)/',
            '/LAST_WEEK\(THURSDAY\)/',
            '/LAST_WEEK\(FRIDAY\)/',
            '/LAST_WEEK\(SATURDAY\)/',
            '/NEXT_WEEK\(\)/',
            '/NEXT_WEEK\(SUNDAY\)/',
            '/NEXT_WEEK\(MONDAY\)/',
            '/NEXT_WEEK\(TUESDAY\)/',
            '/NEXT_WEEK\(WEDNESDAY\)/',
            '/NEXT_WEEK\(THURSDAY\)/',
            '/NEXT_WEEK\(FRIDAY\)/',
            '/NEXT_WEEK\(SATURDAY\)/',
            '/THIS_MONTH\(\)/',
            '/THIS_MONTH\(([1-9]|([1-2][0-9])|(3[0-1]))\)/',
            '/THIS_MONTH\(LAST\)/',
            '/LAST_MONTH\(\)/',
            '/LAST_MONTH\(([1-9]|([1-2][0-9])|(3[0-1]))\)/',
            '/LAST_MONTH\(LAST\)/',
            '/NEXT_MONTH\(\)/',
            '/NEXT_MONTH\(([1-9]|([1-2][0-9])|(3[0-1]))\)/',
            '/NEXT_MONTH\(LAST\)/',
            '/THIS_YEAR\(\)/',
            '/LAST_YEAR\(\)/',
            '/NEXT_YEAR\(\)/'
        ];
        foreach ($regexs as $r) {
            if (preg_match($r, $s)) {
                return true;
            }
        }
        return false;
    }

    // Ref. フィールドコードで使用できない文字: https://jp.cybozu.help/k/ja/user/app_settings/form/autocalc/fieldcode.html
    public const DISALLOWED_FIELD_CHARS = ['(', ')',  '（', '）', '「', '」', '[', ']', '【', '】', '{', '}', '"', ' '];

    /**
     * @param string $field
     * @return boolean
     */
    private static function fieldCheck(string $field): bool
    {
        foreach (self::DISALLOWED_FIELD_CHARS as $disallowedChar) {
            if (strpos($field, $disallowedChar) !== false) {
                return false;
            }
        }
        return true;
    }

    // Ref. 演算子で使用可能な文字: https://developer.cybozu.io/hc/ja/articles/202331474-%E3%83%AC%E3%82%B3%E3%83%BC%E3%83%89%E3%81%AE%E5%8F%96%E5%BE%97-GET-#q1
    public const ALLOWED_SIGN = ['=', '!=', '>', '<', '>=', '<=', 'in', 'not in', 'like', 'not like', 'or', 'and'];

    /**
     * @param string $sign
     * @return boolean
     */
    private static function signCheck(string $sign): bool
    {
        return in_array($sign, self::ALLOWED_SIGN, true);
    }

    /**
     * escape double quote ho"ge -> ho\"ge
     * @param string $s
     * @return string
     */
    private static function escapeDoubleQuote(string $s): string
    {
        return str_replace('"', '\"', $s);
    }

    /**
     * @param string|int|(string|int)[] $val
     * @return string
     * @throws KintoneQueryException
     */
    private static function valToString($val): string
    {
        if (is_string($val)) {
            // you can use function in query
            if (self::funcCheck($val)) {
                return $val;
            }
            return '"' . self::escapeDoubleQuote($val) . '"';
        }
        if (is_int($val)) {
            return (string) $val;
        }
        if (is_array($val)) {
            $list = [];
            foreach ($val as $e) {
                $list[] = self::valToString($e);
            }
            return '(' . implode(',', $list) . ')';
        }
        throw new KintoneQueryException(
            'Invalid $val type: $val must have a string or int or array(used with \'in\' or \'not in\') type, but given ' .
                (is_object($val) ? get_class($val) : (string) $val)
        );
    }

    /**
     * @param string $var
     * @param string $op
     * @param int|string|(int|string)[] $val
     * @return string
     * @throws KintoneQueryException
     */
    public static function genWhereClause($var, $op, $val): string
    {
        if (!self::fieldCheck($var)) {
            throw new KintoneQueryException('Invalid field');
        }
        if (!self::signCheck($op)) {
            throw new KintoneQueryException('Invalid sign');
        }

        // case $op = 'in' or 'not in'
        if ($op === 'in' || $op === 'not in') {
            // expects $val's type to be array
            if (!\is_array($val)) {
                throw new KintoneQueryException(
                    'Invalid $val type: In case $op === \'in\', $val must be array, but given ' .
                        (is_object($val) ? get_class($val) : (string) $val)
                );
            }
        }
        return $var . ' ' . $op . ' ' . self::valToString($val);
    }

    /**
     * @param string $var
     * @param string $op
     * @param int|string|(int|string)[] $val
     * @param string $conj
     * @return $this
     * @throws KintoneQueryException
     */
    private function whereWithVarOpVal(
        string $var,
        string $op,
        $val,
        string $conj
    ): self {
        $this->buffer->append(
            new KintoneQueryBufferElement(
                self::genWhereClause($var, $op, $val),
                $conj
            )
        );
        return $this;
    }

    /**
     * @param KintoneQueryExpr $expr
     * @param string $conj
     * @return $this
     * @throws KintoneQueryException
     */
    private function whereWithExpr(KintoneQueryExpr $expr, string $conj): self
    {
        if ($expr->buffer->isEmpty()) {
            return $this;
        }
        $expr->buffer->setConj($conj);
        $this->buffer->append($expr->buffer);
        return $this;
    }

    /**
     * @param string|KintoneQueryExpr $varOrExpr
     * @param string $op
     * @param int|string|(int|string)[] $val
     * @return $this
     * @throws KintoneQueryException
     */
    public function where($varOrExpr, string $op = '', $val = null): self
    {
        return $this->andWhere($varOrExpr, $op, $val);
    }

    /**
     * @param string|KintoneQueryExpr $varOrExpr
     * @param string $op
     * @param int|string|(int|string)[] $val
     * @return $this
     * @throws KintoneQueryException
     */
    public function andWhere($varOrExpr, string $op = '', $val = null): self
    {
        if ($varOrExpr instanceof self) {
            return $this->whereWithExpr($varOrExpr, 'and');
        }
        if (\is_string($varOrExpr)) {
            return $this->whereWithVarOpVal($varOrExpr, $op, $val, 'and');
        }
        throw new KintoneQueryException(
            'Invalid $varOrExpr: $varOrExpr must be string or KintoneQueryExpr, but given ' .
                (is_object($varOrExpr)
                    ? get_class($varOrExpr)
                    : (string) $varOrExpr)
        );
    }

    /**
     * @param string|KintoneQueryExpr $varOrExpr
     * @param string $op
     * @param int|string|(int|string)[] $val
     * @return $this
     * @throws KintoneQueryException
     */
    public function orWhere($varOrExpr, string $op = '', $val = null): self
    {
        if ($varOrExpr instanceof self) {
            return $this->whereWithExpr($varOrExpr, 'or');
        }
        if (\is_string($varOrExpr)) {
            return $this->whereWithVarOpVal($varOrExpr, $op, $val, 'or');
        }
        throw new KintoneQueryException(
            'Invalid $varOrExpr: $varOrExpr must be string or KintoneQueryExpr, but given ' .
                (is_object($varOrExpr)
                    ? get_class($varOrExpr)
                    : (string) $varOrExpr)
        );
    }
}
