<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRules;
use App\Models\Business;
use App\Models\BusinessAlias;
use App\Models\BusinessContact;
use App\Models\BusinessLocation;
use App\Models\BusinessProduct;
use App\Models\BusinessService as ServiceModel;
use App\Services\Audit\AuditRunner;
use App\Services\AuditLogger;
use App\Services\BusinessService;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRUD for the records that make up a business's identity: aliases, locations, contacts,
 * services and products. One controller, one set of guarantees (tenant + parent checks,
 * audit log, identity re-check) for all of them.
 */
class BusinessChildController extends Controller
{
    private const TYPES = [
        'aliases' => [BusinessAlias::class, 'alias'],
        'locations' => [BusinessLocation::class, 'location'],
        'contacts' => [BusinessContact::class, 'contact'],
        'services' => [ServiceModel::class, 'service'],
        'products' => [BusinessProduct::class, 'product'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AuditRunner $runner,
        private readonly BusinessService $businesses,
    ) {}

    public function index(Request $request, Business $business, string $type): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        [$class] = $this->type($type);

        return response()->json(['data' => $class::query()->where('business_id', $business->id)->orderBy('id')->paginate(100)->items()]);
    }

    public function store(Request $request, Business $business, string $type): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);
        [$class, $rules] = $this->type($type);
        $data = $request->validate(BusinessRules::$rules());
        $this->checkLimits($business, $type, $data);

        $record = match ($type) {
            'contacts' => $this->businesses->addContact($business, $data),
            'locations' => $class::create($data + ['business_id' => $business->id, 'country' => strtoupper($data['country'] ?? $business->country)]),
            default => $class::create($data + ['business_id' => $business->id]),
        };
        $this->audit->log(Str::singular($type).'.created', $record, null, $data);
        $this->runner->refreshIdentity($business);

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, Business $business, string $type, int $id): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $record = $this->find($business, $type, $id);
        [, $rules] = $this->type($type);
        $partial = array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), BusinessRules::$rules());
        $data = $request->validate($partial);
        $this->checkLimits($business, $type, $data, $record);
        if ($type === 'contacts' && isset($data['value'])) {
            $data['normalized_value'] = in_array($data['type'] ?? $record->type, ['phone', 'whatsapp'], true)
                ? Phone::normalize($data['value'], $business->country) : strtolower(trim($data['value']));
        }
        $before = $record->only(array_keys($data));
        $record->update($data);
        $this->audit->log(Str::singular($type).'.updated', $record, $before, $data);
        $this->runner->refreshIdentity($business);

        return response()->json(['data' => $record]);
    }

    public function destroy(Business $business, string $type, int $id): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $record = $this->find($business, $type, $id);
        $record->delete();
        $this->audit->log(Str::singular($type).'.deleted', $record, $record->toArray(), null);
        $this->runner->refreshIdentity($business);

        return response()->json(null, 204);
    }

    private function type(string $type): array
    {
        return self::TYPES[$type] ?? throw new NotFoundHttpException;
    }

    private function find(Business $business, string $type, int $id): Model
    {
        $this->ensureInWorkspace($business);
        [$class] = $this->type($type);
        $record = $class::query()->where('business_id', $business->id)->findOrFail($id);
        $this->ensureBelongs($record, $business);

        return $record;
    }

    private function checkLimits(Business $business, string $type, array $data, ?Model $existing = null): void
    {
        if ($type === 'locations' && ! $existing) {
            $limit = $business->workspace->entitlements()['locations_per_business'];
            if ($limit !== null && $business->locations()->count() >= $limit) {
                throw ValidationException::withMessages(['plan' => "Your plan includes {$limit} location(s) per business."]);
            }
        }
        if ($type === 'contacts' && ! empty($data['business_location_id'])) {
            if (! $business->locations()->whereKey($data['business_location_id'])->exists()) {
                throw ValidationException::withMessages(['business_location_id' => 'That location does not belong to this business.']);
            }
        }
    }
}
