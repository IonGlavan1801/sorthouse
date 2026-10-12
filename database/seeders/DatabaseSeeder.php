<?php

namespace Database\Seeders;

use App\Domain\Extraction\SchemaCatalog;
use App\Models\AutomationPolicy;
use App\Models\EvaluationDataset;
use App\Models\ExtractionSchema;
use App\Models\PolicyVersion;
use App\Models\SchemaVersion;
use Illuminate\Database\Seeder;

/** Reference data only: schemas (immutable versions), policy versions and evaluation datasets. All demo content is synthetic. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SchemaCatalog::definitions() as $version => $def) {
            $schema = ExtractionSchema::firstOrCreate(['key' => $def['schema']], ['title' => $def['title']]);
            $full = SchemaCatalog::definition($version);
            SchemaVersion::firstOrCreate(['extraction_schema_id' => $schema->id, 'version' => $version], [
                'definition' => $full, 'definition_hash' => hash('sha256', json_encode($full)),
            ]);
        }

        $policy = AutomationPolicy::firstOrCreate(['key' => 'default']);
        $v1 = PolicyVersion::firstOrCreate(['automation_policy_id' => $policy->id, 'version' => 'policy.v1'], ['rules' => config('sorthouse_policy.v1')]);
        PolicyVersion::firstOrCreate(['automation_policy_id' => $policy->id, 'version' => 'policy.v2-candidate'], ['rules' => config('sorthouse_policy.v2')]);
        if (! $policy->active_version_id) {
            $policy->update(['active_version_id' => $v1->id]);
        }

        foreach (['dev' => 'Tuning/development set (used to choose thresholds)', 'holdout' => 'Held-out evaluation set (never used for tuning)'] as $key => $description) {
            EvaluationDataset::firstOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}
