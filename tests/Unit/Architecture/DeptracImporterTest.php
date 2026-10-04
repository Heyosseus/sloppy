<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\DeptracImporter;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\ProfileProposal;

const DEPTRAC_YAML = <<<'YAML'
deptrac:
  paths: [./src]
  layers:
    - name: Controller
      collectors:
        - type: classLike
          value: App\\Controller\\.*
    - name: Service
      collectors:
        - type: className
          value: .*Service$
        - type: directory
          value: src/Service/.*
    - name: Repository
      collectors:
        - type: classNameRegex
          value: '#^App\\Repository\\.*#'
        - type: interface
          value: .*RepositoryInterface.*
    - name: Contract
      collectors:
        - type: implements
          value: \App\Contract\Marker
        - type: inherits
          value: App\Base
        - type: extends
          value: App\Model
        - type: uses
          value: App\Concerns\Audits
        - type: attribute
          value: App\Attributes\Layer
        - type: glob
          value: src/Contract/*.php
    - name: Domain Model
      collectors:
        - type: bool
          must:
            - type: className
              value: App\\Domain\\.*
          must_not:
            - type: className
              value: .*Test.*
    - name: Strict
      collectors:
        - type: bool
          must:
            - type: className
              value: App\\Strict\\.*
            - type: interface
              value: .*Strict.*
    - name: Alias
      collectors:
        - type: layer
          value: Controller
    - name: Regex
      collectors:
        - type: className
          value: App\\(Foo|Bar)\\.*
    - name: 42
      collectors: []
  ruleset:
    Controller: [Service, +Repository]
    Service:
      - Repository
      - Missing
    Repository: ~
YAML;

function importDeptrac(string $yaml): ProfileProposal
{
    $root = tempProject(['deptrac.yaml' => $yaml]);
    $proposal = (new DeptracImporter)->import($root.'/deptrac.yaml', 'deptrac.yaml');
    removeTree($root);

    return $proposal;
}

it('translates layers into roles, collectors into matchers and the ruleset into allow lists', function (): void {
    $proposal = importDeptrac(DEPTRAC_YAML);
    $roles = $proposal->profile['roles'];

    expect($proposal->profile['preset'])->toBe('none')
        ->and(array_keys($roles))->toBe(['controller', 'service', 'repository', 'contract', 'domain-model', 'strict', 'alias'])
        ->and($roles['controller'])->toBe(['description' => 'Layer Controller in deptrac.', 'namespace' => '*App\Controller\*'])
        ->and($roles['service'])->toBe(['description' => 'Layer Service in deptrac.', 'any' => [['namespace' => '*Service'], ['path' => '*src/Service/*']]])
        ->and($roles['repository']['any'])->toBe([['namespace' => 'App\Repository\*'], ['kind' => 'interface', 'namespace' => '*RepositoryInterface*']])
        ->and($roles['contract']['any'])->toBe([
            ['implements' => 'App\Contract\Marker'],
            ['any' => [['extends' => 'App\Base'], ['implements' => 'App\Base']]],
            ['extends' => 'App\Model'],
            ['uses' => 'App\Concerns\Audits'],
            ['attribute' => 'App\Attributes\Layer'],
            ['path' => 'src/Contract/*.php'],
        ])
        ->and($roles['domain-model'])->toBe(['description' => 'Layer Domain Model in deptrac.', 'namespace' => '*App\Domain\*', 'not' => ['namespace' => '*Test*']])
        ->and($roles['strict'])->toBe(['description' => 'Layer Strict in deptrac.', 'namespace' => '*App\Strict\*', 'not' => ['not' => ['kind' => 'interface', 'namespace' => '*Strict*']]])
        ->and($roles['alias'])->toBe(['description' => 'Layer Alias in deptrac.', 'namespace' => '*App\Controller\*'])
        ->and($proposal->profile['policies'])->toBe([
            'controller' => ['may_depend_on' => ['service', 'repository']],
            'service' => ['may_depend_on' => ['repository']],
            'repository' => ['may_depend_on' => []],
            'contract' => ['may_depend_on' => []],
            'domain-model' => ['may_depend_on' => []],
            'strict' => ['may_depend_on' => []],
            'alias' => ['may_depend_on' => []],
        ])
        ->and($proposal->notes)->toBe([
            'Imported from deptrac.yaml.',
            sprintf('Could not translate the className collector %s; matched by nothing instead.', json_encode(['type' => 'className', 'value' => 'App\\\\(Foo|Bar)\\\\.*'], JSON_UNESCAPED_SLASHES)),
            'Skipped layer Regex: none of its collectors translate.',
            'Skipped a layer whose name cannot be a role: {"name":42,"collectors":[]}.',
            'Layer Controller inherits Repository\'s dependencies in deptrac (+Repository); list them for controller if it needs them.',
            'Layer Service may depend on Missing, which was not imported.',
        ]);

    $proposal->validate();
});

it('reads the old depfile layout, and folds several must collectors and a negated one together', function (): void {
    $proposal = importDeptrac(<<<'YAML'
    parameters:
      layers:
        - name: Both
          collectors:
            - type: bool
              must:
                - type: bool
                  must: [{type: className, value: A.*}]
                  must_not: [{type: className, value: .*Test}]
                - type: className
                  value: .*B
              must_not:
                - type: className
                  value: .*C
                - type: className
                  value: .*D
        - name: Broken
          collectors:
            - type: bool
              must: [{type: functionName, value: foo}]
            - type: bool
              must: []
            - not-a-collector
            - type: className
            - type: layer
              value: Nowhere
            - value: Untyped
    YAML);

    expect($proposal->profile['roles'])->toBe([
        'both' => [
            'description' => 'Layer Both in deptrac.',
            'any' => [['namespace' => '*A*', 'not' => ['namespace' => '*Test*']]],
            'not' => ['any' => [['not' => ['namespace' => '*B*']], ['namespace' => '*C*'], ['namespace' => '*D*']]],
        ],
    ])
        ->and($proposal->profile)->toHaveKey('policies')
        ->and(implode("\n", $proposal->notes))->toContain('Could not translate the functionName collector')
        ->and(implode("\n", $proposal->notes))->toContain('Could not translate the layer collector {"type":"layer","value":"Nowhere"}')
        ->and(implode("\n", $proposal->notes))->toContain('Could not translate the untyped collector {"value":"Untyped"}')
        ->and(implode("\n", $proposal->notes))->toContain('Skipped layer Broken');

    $proposal->validate();
});

it('takes a file with no ruleset at all', function (): void {
    $proposal = importDeptrac("layers:\n  - name: Web\n    collectors:\n      - type: classLike\n        value: ^App\\\\Http\\\\.*$\n");

    expect($proposal->profile['roles']['web'])->toBe(['description' => 'Layer Web in deptrac.', 'namespace' => 'App\Http\*'])
        ->and($proposal->profile['policies'])->toBe(['web' => ['may_depend_on' => []]]);
});

it('refuses a file that is not deptrac configuration', function (string $yaml, string $message): void {
    expect(static fn (): ProfileProposal => importDeptrac($yaml))->toThrow(ProfileException::class, $message);
})->with([
    'not yaml' => ["layers: [\n", 'deptrac.yaml is not valid YAML:'],
    'no layers' => ["deptrac:\n  paths: [src]\n", 'deptrac.yaml has no deptrac layers to import.'],
    'a scalar' => ['just text', 'deptrac.yaml has no deptrac layers to import.'],
]);

it('finds deptrac configuration by its usual names', function (): void {
    $none = tempProject();
    $old = tempProject(['depfile.yml' => 'layers: []']);
    $both = tempProject(['deptrac.yaml' => 'layers: []', 'depfile.yaml' => 'layers: []']);

    expect(DeptracImporter::find($none))->toBeNull()
        ->and(DeptracImporter::find($old))->toBe('depfile.yml')
        ->and(DeptracImporter::find($both))->toBe('deptrac.yaml');

    removeTree($none);
    removeTree($old);
    removeTree($both);
});

it('takes a bool collector with one must and nothing negated as that collector', function (): void {
    $proposal = importDeptrac(<<<'YAML'
    layers:
      - name: Only
        collectors:
          - type: bool
            must:
              - type: className
                value: ^App\\Only\\.*
    YAML);

    expect($proposal->profile['roles']['only'])->toBe(['description' => 'Layer Only in deptrac.', 'namespace' => 'App\Only\*']);
});
