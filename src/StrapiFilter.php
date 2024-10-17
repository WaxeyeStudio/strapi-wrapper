<?php

namespace SilentWeb\StrapiWrapper;

abstract class StrapiFilter
{
    public const Equals = '$eq';
    public const Not_Equal = '$ne';
    public const Less_than = '$lt';
    public const Less_than_equal = '$lte';
    public const Greater_than = '$gt';
    public const Greater_than_equal = '$gte';
    public const In_array = '$in';
    public const No_in_array = '$notIn';
    public const Contains = '$contains';
    public const Does_not_contain = '$notContains';
    public const Contain_case_insensitive = '$containsi';
    public const Does_not_contain_case_insensitive = '$notContainsi';
    public const Is_null = '$null';
    public const Is_not_null = '$notNull';
    public const Between = '$between';
    public const Starts_with = '$startsWith';
    public const Ends_with = '$endsWith';
}
