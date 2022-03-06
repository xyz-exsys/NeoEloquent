<?php

namespace Vinelab\NeoEloquent\Eloquent;

use Illuminate\Support\Str;
use Vinelab\NeoEloquent\Eloquent\Relations\BelongsTo;
use Vinelab\NeoEloquent\Eloquent\Relations\HasOne;

use function class_basename;
use function is_string;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    /**
     * @return static
     */
    public function setLabel(string $label): self
    {
        $this->table = $label;

        return $this;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->table;
    }

    public function getTable(): string
    {
        return $this->table ?? Str::studly(class_basename($this));
    }

    public function nodeLabel(): string
    {
        return $this->getTable();
    }

    /**
     * Create a model with its relations.
     *
     * @param array $attributes
     * @param array $relations
     * @param array $options
     *
     * @return Model|false
     */
    public static function createWith(array $attributes, array $relations, array $options = [])
    {
        // we need to fire model events on all the models that are involved with our operaiton,
        // including the ones from the relations, starting with this model.
        $me = new static();
        $me->fill($attributes);
        $models = [$me];

        $query = static::query();
        // add parent model's mutation constraints
        $label = $query->getQuery()->getGrammar()->modelAsNode($me->getDefaultNodeLabel());
        $query->addManyMutation($label, $me);

        // setup relations
        foreach ($relations as $relation => $values) {
            $related = $me->$relation()->getRelated();

            // if the relation holds the attributes directly instead of an array
            // of attributes, we transform it into an array of attributes.
            if (!$values instanceof Collection && (!is_array($values) || Arr::isAssoc($values))) {
                $values = [$values];
            }

            // create instances with the related attributes so that we fire model
            // events on each of them.
            foreach ($values as $relatedModel) {
                // one may pass in either instances or arrays of attributes, when we get
                // attributes we will dynamically fill a new model instance of the related model.
                if (is_array($relatedModel)) {
                    $model = $related->newInstance();
                    $model->fill($relatedModel);
                    $relatedModel = $model;
                }

                $models[$relation][] = $relatedModel;
                $query->addManyMutation($relation, $related);
            }
        }

        $existingModelsKeys = [];
        // fire 'creating' and 'saving' events on all models.
        foreach ($models as $relation => $related) {
            if (!is_array($related)) {
                $related = [$related];
            }

            foreach ($related as $model) {
                // we will fire model events on actual models, however attached models using IDs will not be considered.
                if ($model instanceof Model) {
                    if (!$model->exists && $model->fireModelEvent('creating') === false) {
                        return false;
                    }

                    if($model->exists) {
                        $existingModelsKeys[] = $model->getKey();
                    }

                    if ($model->fireModelEvent('saving') === false) {
                        return false;
                    }
                } else {
                    $existingModelsKeys[] = $model;
                }
            }
        }

        // remove $me from $models so that we send them as relations.
        array_shift($models);
        // run the query and create the records.
        $result = $query->createWith($me->toArray(), $models);
        // take the parent model that was created out of the results array based on
        // this model's label.
        $created = reset($result[$label]);
        // fire 'saved' and 'created' events on parent model.
        $created->finishSave($options);
        $created->fireModelEvent('created', false);

        // set related models as relations on the parent model.
        foreach ($relations as $method => $values) {
            $relation = $created->$method();
            // is this a one-to-one relation ? If so then we add the model directly,
            // otherwise we create a collection of the loaded models.
            $related = new Collection($result[$method]);
            // fire model events 'created' and 'saved' on related models.
            $related->each(function ($model) use ($options, $existingModelsKeys) {
                $model->finishSave($options);
                // var_dump(get_class($model), 'saved');

                if(!in_array($model->getKey(), $existingModelsKeys)) {
                    $model->fireModelEvent('created', false);
                }
            });

            // when the relation is 'One' instead of 'Many' we will only return the retrieved instance
            // instead of colletion.
            if ($relation instanceof OneRelation || $relation instanceof HasOne || $relation instanceof BelongsTo) {
                $related = $related->first();
            }

            $created->setRelation($method, $related);
        }

        return $created;
    }

    /**
     * @param array|string $labels
     */
    public function addLabels($labels): bool
    {
        return $this->updateLabels($labels, 'add');
    }

    /**
     * @param array|string $labels
     */
    public function dropLabels($labels): bool
    {
        return $this->updateLabels($labels, 'drop');
    }

    /**
     * @param array|string $labels
     */
    public function updateLabels($labels, $operation = 'add'): bool
    {
        $query = $this->newQueryWithoutScopes();
        if (is_string($labels)) {
            $labels = [$labels];
        }

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This gives an opportunities to
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if (!is_array($labels) || count($labels) == 0) {
            return false;
        }

        foreach ($labels as $label) {
            if (!preg_match('/^[a-z]([a-z0-9]+)$/i', $label)) {
                return false;
            }
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll return false.
        if ($this->exists) {
            $this->setKeysForSaveQuery($query)->updateLabels($labels, $operation);
            $this->fireModelEvent('updated', false);
        } else {
            return false;
        }

        return true;
    }
}
