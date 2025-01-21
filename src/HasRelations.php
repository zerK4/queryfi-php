<?php

namespace Z3rka\Queryfi;

use Log;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Z3rka\HasRelations\Helpers\Utils;

trait HasRelations
{
    /**
     * Process and apply all query modifications dynamically.
     */
    public function withRelation(Request $request, Builder $builder): mixed
    {
        try {
            $this->applyDirectQueryMethods($request, $builder);

            // Apply relationships with nested queries
            $this->applyRelationships($request, $builder);

            // Apply column selection
            $this->applySelect($request, $builder);

            if ($request->has('paginate')) {
                return $builder->paginate($request->input('paginate'));
            }

            if (in_array($request->get("getter"), ["first", "get"])) {
                return $builder->{$request->get("getter")}();
            }

            if ($request->has('action') && $request->get('action') === 'count') {
                return $builder->{$request->get("action")}();
            }


            return $builder;
        } catch (Exception $e) {
            Log::error("Query modification failed", ['error' => $e->getMessage()]);
            return $builder;
        }
    }


    /**
     * Apply direct query methods from the request.
     */
    protected function applyDirectQueryMethods(Request $request, Builder $builder): void
    {
        $directMethods = [
            'where' => 'applyWhere',
            'orWhere' => 'applyWhere',
            'orderBy' => 'applyOrderBy',
            'limit' => 'applyLimit',
            'offset' => 'applyOffset'
        ];

        foreach ($directMethods as $param => $method) {
            if ($request->has($param)) {
                $this->{$method}($builder, $request->input($param), $param);
            }
        }
    }

    /**
     * Apply where conditions flexibly.
     */
    protected function applyWhere(Builder|Relation $query, array $conditions, ?string $method): void
    {
        $table = $query->getModel()->getTable();

        foreach ($conditions as $column => $operators) {
            if (is_array($operators)) {
                foreach ($operators as $operator => $value) {
                    if (in_array($operator, ['whereBetween', 'whereNotBetween'])) {
                        $value = Utils::stringToArray($value);
                        if (!$value || count($value) !== 2) {
                            Log::error("Invalid between parameters", [
                                'value' => $value,
                                'operator' => $operator
                            ]);
                            continue;
                        }

                        $query->{$operator}("{$table}.{$column}", $value);
                    } elseif (in_array($operator, ['whereIn', 'whereNotIn'])) {
                        $value = Utils::stringToArray($value);
                        if (!$value) {
                            Log::error("Invalid in parameters", [
                                'value' => $value,
                                'operator' => $operator
                            ]);
                            continue;
                        }
                        $query->{$operator}("{$table}.{$column}", $value);
                    } elseif (in_array($operator, ['>', '<', '>=', '<=', '!=', '=', 'like', 'not like'])) {
                        $query->{$method}("{$table}.{$column}", $operator, $value);
                    }
                }
            } else {
                $query->{$method}("{$table}.{$column}", '=', Utils::isStringifiedBoolean($operators));
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

        // Group relations and their sub-relations
        $relationsMap = [];

        foreach ($relations as $relation) {
            // Check if the relation has nested sub-relations (i.e., square brackets)
            if (strpos($relation, '[') !== false && strpos($relation, ']') !== false) {
                // Split relation into base relation and sub-relations
                preg_match('/(.*?)\[(.*?)\]/', $relation, $matches);
                $baseRelation = $matches[1];
                // Replace '&' with ',' and split sub-relations
                $subRelations = explode("&", str_replace(",", "&", $matches[2]));
                $relationsMap[$baseRelation] = $subRelations;
            } else {
                // If no sub-relations, treat it as a top-level relation
                $relationsMap[$relation] = [];
            }
        }

        // Apply the relationships with their sub-relations
        foreach ($relationsMap as $baseRelation => $subRelations) {
            $builder->with([
                $baseRelation => function ($query) use ($subRelations) {
                    if (!empty($subRelations)) {
                        // Apply the sub-relations dynamically
                        foreach ($subRelations as $subRelation) {
                            $query->with($subRelation);
                        }
                    }
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
