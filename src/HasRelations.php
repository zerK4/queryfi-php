<?php

namespace Z3rka\Queryfi;

use Log;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Z3rka\HasRelations\Helpers\Utils;
use Collection;

trait HasRelations
{
    /**
     * Process and apply all query modifications dynamically.
     */
    public function withRelation(Request $request, Builder $builder): mixed
    {
        try {
            // Apply direct query methods first
            $this->applyDirectQueryMethods($request, $builder);

            // Apply relationships with nested queries
            $this->applyRelationships($request, $builder);

            // Apply column selection
            $this->applySelect($request, $builder);

            $this->applyPagination($request, $builder);

            if (in_array($request->get("getter"), ["first", "get"])) {
                return $builder->{$request->get("getter")}();
            } else {
                return $builder->get();
            }

            return $builder;
        } catch (Exception $e) {
            Log::error("Query modification failed", ['error' => $e->getMessage()]);
            return $builder;
        }
    }

    protected function applyPagination(Request $request, Builder $query)
    {
        if ($request->has('paginate')) {
            return $query->paginate($request->input('paginate'));
        }

        return $query;
    }

    /**
     * Apply direct query methods from the request.
     */
    protected function applyDirectQueryMethods(Request $request, Builder $builder): void
    {
        $directMethods = [
            'where' => 'applyWhere',
            'orderBy' => 'applyOrderBy',
            'limit' => 'applyLimit',
            'offset' => 'applyOffset',
        ];

        foreach ($directMethods as $param => $method) {
            if ($request->has($param)) {
                $this->{$method}($builder, $request->input($param));
            }
        }
    }

    /**
     * Apply where conditions flexibly.
     */
    protected function applyWhere(Builder|Relation $query, array $conditions): void
    {
        $table = $query->getModel()->getTable();

        foreach ($conditions as $column => $operators) {
            if (is_array($operators)) {
                foreach ($operators as $operator => $value) {
                    // Handle the case for ranges (e.g., between, not between)
                    if (in_array($operator, ['whereBetween', 'whereNotBetween'])) {
                        // Ensure $value can be imploded to an array
                        $value = Utils::stringToArray($value);

                        if (!$value) {
                            Log::error('Could not convert to array!', [
                                'params' => $value,
                                'operator' => $operator
                            ]);
                            return;
                        }

                        if (count($value) !== 2) {
                            Log::error('where between || whereNotBetween require no more or less than 2 params', [
                                'params' => $value,
                                'operator' => $operator
                            ]);
                            return;
                        }

                        $query->{$operator}("{$table}.{$column}", $value);
                    }
                    // Handle "in" or "not in" operators
                    elseif (in_array($operator, ['whereIn', 'whereNotIn'])) {
                        // Ensure $value is an array
                        $value = Utils::stringToArray($value);
                        if (!$value) {
                            Log::error('Could not convert to array!', [
                                'params' => $value,
                                'operator' => $operator
                            ]);
                            return;
                        }

                        $query->{$operator}("{$table}.{$column}", $value);
                    }
                    // Handle comparison operators (>, <, >=, <=, !=, =, like, not like)
                    elseif (in_array($operator, ['>', '<', '>=', '<=', '!=', '=', 'like', 'not like'])) {
                        $query->where("{$table}.{$column}", $operator, $value);
                    }
                }
            } else {
                // Simple equality cond ition
                $query->where("{$table}.{$column}", '=', $operators);
            }
        }
    }



    /**
     * Apply orderBy with flexible syntax.
     */
    protected function applyOrderBy(Builder $builder, array|string $arguments): void
    {
        if (is_string($arguments)) {
            // Simple string input like 'created_at:desc'
            $parts = explode(':', $arguments);
            $column = $parts[0];
            $direction = $parts[1] ?? 'asc';
            $builder->orderBy($column, $direction);
            return;
        }

        foreach ($arguments as $column => $direction) {
            if (is_numeric($column)) {
                // Support for array input like ['created_at:desc']
                $parts = explode(':', $direction);
                $column = $parts[0];
                $direction = $parts[1] ?? 'asc';
            }
            $builder->orderBy($column, $direction);
        }
    }

    /**
     * Apply limit to the query.
     */
    protected function applyLimit(Builder $builder, int $limit): void
    {
        $builder->limit($limit);
    }

    /**
     * Apply offset to the query.
     */
    protected function applyOffset(Builder $builder, int $offset): void
    {
        $builder->offset($offset);
    }

    /**
     * Handle eager loading relationships with nested queries.
     */
    protected function applyRelationships(Request $request, Builder $builder): void
    {
        if (!$request->has('with')) {
            return;
        }
        $relations = is_string($request->input('with'))
            ? explode(",", $request->input('with'))
            : $request->input('with');

        foreach ($relations as $relation) {
            $nestedRelations = is_string($relation)
                ? explode(".", $relation)
                : $relation;

            $baseRelation = array_shift($nestedRelations);

            $builder->with([
                $baseRelation => function ($query) use ($nestedRelations, $request, $baseRelation) {
                    if (!empty($nestedRelations)) {
                        $query->with(implode(".", $nestedRelations));
                    }
                    $this->applyRelationQueryModifiers($query, $request, $baseRelation);
                }
            ]);
        }
    }

    /**
     * Apply query modifiers for related models.
     */
    protected function applyRelationQueryModifiers(Builder|Relation $query, Request $request, string $baseRelation): void
    {
        $relationKey = "query_{$baseRelation}";

        if (!$request->has($relationKey)) {
            return;
        }

        $modifiers = $request->input($relationKey);

        foreach ($modifiers as $method => $arguments) {
            // Explicitly handle common query methods
            switch ($method) {
                case 'where':
                    if (is_array($arguments)) {
                        foreach ($arguments as $column => $value) {
                            $query->where($column, $value);
                        }
                    }
                    break;
                case 'orderBy':
                    // Handle both string "column,direction" and array formats
                    if (is_string($arguments)) {
                        $parts = explode(',', $arguments);
                        $query->orderBy($parts[0], $parts[1] ?? 'asc');
                    } elseif (is_array($arguments)) {
                        foreach ($arguments as $order) {
                            if (is_array($order)) {
                                $query->orderBy($order[0], $order[1] ?? 'asc');
                            }
                        }
                    }
                    break;
                case 'limit':
                    $query->limit($arguments);
                    break;
                case 'select':
                    $query->select(is_string($arguments)
                        ? explode(',', $arguments)
                        : $arguments);
                    break;
            }
        }
    }

    /**
     * Apply select clauses to the query.
     */
    protected function applySelect(Request $request, Builder $builder): void
    {
        if ($request->has('select')) {
            $columns = is_string($request->input('select'))
                ? explode(',', $request->input('select'))
                : $request->input('select');

            $builder->select($columns);
        }
    }

    /**
     * Process the model query with optional pagination.
     */
    public function processModel(Request $request, Model $model): mixed
    {
        try {
            $query = $model->newQuery();

            return $this->withRelation($request, $query);
        } catch (Exception $e) {
            Log::error("Model processing failed", ['error' => $e->getMessage()]);
            return $model->newQuery();
        }
    }
}
