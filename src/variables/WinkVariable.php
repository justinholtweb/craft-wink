<?php

namespace justinholtweb\wink\variables;

use justinholtweb\wink\elements\db\ExperimentQuery;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\Plugin;

class WinkVariable
{
    /**
     * Returns an ExperimentQuery.
     * Usage: {{ craft.wink.experiments.experimentStatus('running').all() }}
     */
    public function experiments(): ExperimentQuery
    {
        return Experiment::find();
    }

    /**
     * Get a running experiment by handle.
     */
    public function experiment(string $handle): ?Experiment
    {
        return Plugin::getInstance()->experiments->getRunningExperiment($handle);
    }

    /**
     * Get the assigned variant handle for an experiment.
     */
    public function variant(string $experimentHandle): ?string
    {
        $variant = Plugin::getInstance()->assignment->getAssignment($experimentHandle);
        return $variant?->handle;
    }

    /**
     * Check if tracking is enabled.
     */
    public function isTrackingEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->enableTracking;
    }
}
