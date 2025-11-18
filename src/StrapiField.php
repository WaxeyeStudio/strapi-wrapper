<?php

namespace SilentWeb\StrapiWrapper;

class StrapiField
{
    private string $name;

    private StrapiCollection $collection;

    private array $filters = [];

    private array $linkedFilters = [];

    public function __construct(string $fieldName, StrapiCollection $collection)
    {
        $this->name = $fieldName;
        $this->collection = $collection;
    }

    /*
     * Used to query a particular field for multiple values
     * eg ->valueOr(['slugValue', 'slugValue-2'], '$eq')
     * This field can match either slugValue or slugValue-2
     */
    public function valueOr(array $values, string $comparisonMethod = StrapiFilter::Equals): StrapiCollection
    {
        foreach ($values as $index => $value) {
            $calculatedFieldName = '$or.'.$index.'.'.$this->name;
            $this->collection->field($calculatedFieldName)->filter($value, $comparisonMethod);
            $this->linkedFilters[] = $calculatedFieldName;
        }

        return $this->collection;
    }

    /*
     * Used to find a particular value that may appear in multiple fields
     * eg ->valueOr('slugValue', ['title', 'slug'], '$eq')
     * This 'slugValue' can match either title or slug
     */

    public function filter($value, string $how = StrapiFilter::Equals): StrapiCollection
    {
        $this->filters[$how] = $value;

        return $this->collection;
    }

    public function valueIn($value, array $otherFields, string $comparisonMethod = StrapiFilter::Equals): StrapiCollection
    {
        $this->multiFilter('$or.', $value, $comparisonMethod, [$this->name, ...$otherFields]);

        return $this->collection;
    }

    private function multiFilter($prefix, $by, $how, $fields): void
    {
        foreach ($fields as $index => $field) {
            $what = $prefix.$index.'.'.$field;
            $this->collection->field($what)->filter($by, $how);
            $this->linkedFilters[] = $what;
        }
    }

    /**
     * @deprecated Will be reworked in future versions
     */
    public function or($value, string $how = StrapiFilter::Equals, array $fields = []): StrapiCollection
    {
        $this->multiFilter('$or.', $value, $how, [$this->name, ...$fields]);

        return $this->collection;
    }

    /**
     * @deprecated Will be reworked in future versions
     */
    public function and($value, string $how = StrapiFilter::Equals, array $fields = []): StrapiCollection
    {
        $this->multiFilter('$and.', $value, $how, [$this->name, ...$fields]);

        return $this->collection;
    }

    public function url(): string
    {
        $builder = [];
        foreach ($this->filters as $how => $value) {
            if ($this->collection->apiVersion() === 4 || $this->collection->apiVersion() === 5) {
                // Check for deep filtering
                $deep = explode('.', $this->name);
                if (count($deep) > 1) {
                    $builder[] = 'filters['.implode('][', $deep)."][$how]=".urlencode($value);
                } else {
                    $builder[] = 'filters['.$this->name."][$how]=".urlencode($value);
                }
            }
        }

        return implode('&', $builder);
    }

    public function clearFilter($how): StrapiField
    {
        if ($this->filters[$how]) {
            unset($this->filters[$how]);
        }

        return $this;
    }

    public function clearFilters(): StrapiField
    {
        $this->filters = [];
        foreach ($this->linkedFilters as $filter) {
            $this->collection->field($filter)->clearFilters();
        }

        return $this;
    }
}
