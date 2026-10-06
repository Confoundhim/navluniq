<?php

namespace App\Livewire\Concerns;

use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\SavedAddress;
use App\Support\BodyTypes;
use App\Support\Phone;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Yük sahibi ilan formunun ortak alanları: ilan oluşturma sihirbazı ve ilan düzenleme ekranı aynı alanları, kuralları ve
 * adres defteri doldurmasını buradan alır (kopya form yok). Rota il/ilçe seçiciyle girilir; açık adres, yükleme yetkilisi ve
 * şoföre not yalnız yük sahibi, ödemesi alınmış şoför ve yönetici tarafından görülür (plan E6/E7).
 */
trait ManagesLoadForm
{
    public string $pickup_province_code = '';

    public string $pickup_district = '';

    public string $pickup_address_private = '';

    public string $pickup_contact_name = '';

    public string $pickup_contact_phone = '';

    public string $delivery_province_code = '';

    public string $delivery_district = '';

    public string $delivery_address_private = '';

    public string $pickup_date = '';

    public string $delivery_date = '';

    public string $selected_saved_pickup = '';

    public string $selected_saved_delivery = '';

    public string $goods_type = '';

    public string $vehicle_type = '';

    /** İstenen kasa tipleri (çoklu); boş: fark etmez. */
    public array $body_types = [];

    /** komple | parca */
    public string $load_kind = 'komple';

    public string $weight = '';

    public string $volume = '';

    public string $e_irsaliye_no = '';

    /** Şoföre not (yükleme saati, forklift, palet sayısı…); en çok Load::NOTES_MAX karakter. */
    public string $notes = '';

    public string $price = '';

    /** Araç sınıfı değişince o sınıfta geçersiz kasa seçimleri düşer. */
    public function updatedVehicleType(): void
    {
        $this->body_types = array_values(array_intersect(BodyTypes::clean($this->body_types), $this->bodyOptions()));
    }

    /** İl değişince eski ilin ilçesi kalmaz. */
    public function updatedPickupProvinceCode(): void
    {
        $this->pickup_district = '';
    }

    public function updatedDeliveryProvinceCode(): void
    {
        $this->delivery_district = '';
    }

    /** Seçili araç sınıfında geçerli kasa tipleri. */
    public function bodyOptions(): array
    {
        return BodyTypes::forClass(VehicleTypes::classOf($this->vehicle_type));
    }

    public function updatedSelectedSavedPickup(string $value): void
    {
        if ($address = $this->savedAddress($value)) {
            $this->applySavedAddress($address, 'pickup');
        }
    }

    public function updatedSelectedSavedDelivery(string $value): void
    {
        if ($address = $this->savedAddress($value)) {
            $this->applySavedAddress($address, 'delivery');
        }
    }

    /** Adres defteri kaydı: il/ilçe seçiciye, açık adres gizli alana, yetkili yalnız yükleme tarafına taşınır. */
    protected function applySavedAddress(SavedAddress $address, string $side): void
    {
        $code = TurkishLocations::provinceCode($address->city);
        $this->{$side.'_province_code'} = $code ? (string) $code : '';
        $district = trim((string) $address->district);
        $this->{$side.'_district'} = $code && in_array($district, TurkishLocations::districtsOf($code), true) ? $district : '';
        $this->{$side.'_address_private'} = (string) $address->address_detail;
        if ($side === 'pickup') {
            $this->pickup_contact_name = (string) $address->contact_person;
            $this->pickup_contact_phone = Phone::format($address->contact_phone);
        }
    }

    protected function savedAddress(string $id): ?SavedAddress
    {
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return SavedAddress::query()->whereKey((int) $id)->where('user_id', Auth::id())->first();
    }

    /** @return array{0: array, 1: array} kurallar ve mesajlar */
    protected function routeRules(): array
    {
        $codes = array_map(fn (array $p) => (string) $p['code'], TurkishLocations::provinces());
        $pickupDistricts = $this->pickup_province_code !== '' ? TurkishLocations::districtsOf((int) $this->pickup_province_code) : [];
        $deliveryDistricts = $this->delivery_province_code !== '' ? TurkishLocations::districtsOf((int) $this->delivery_province_code) : [];

        return [[
            'pickup_province_code' => ['required', Rule::in($codes)],
            'pickup_district' => ['nullable', Rule::in($pickupDistricts)],
            'delivery_province_code' => ['required', Rule::in($codes)],
            'delivery_district' => ['nullable', Rule::in($deliveryDistricts)],
        ] + $this->dateRules()[0] + $this->privateRules()[0], [
            'pickup_province_code.required' => 'Yükleme ilini seçin.',
            'pickup_province_code.in' => 'Yükleme ilini listeden seçin.',
            'pickup_district.in' => 'Yükleme ilçesini listeden seçin.',
            'delivery_province_code.required' => 'Teslimat ilini seçin.',
            'delivery_province_code.in' => 'Teslimat ilini listeden seçin.',
            'delivery_district.in' => 'Teslimat ilçesini listeden seçin.',
        ] + $this->dateRules()[1] + $this->privateRules()[1]];
    }

    /** @return array{0: array, 1: array} */
    protected function dateRules(): array
    {
        return [[
            'pickup_date' => 'required|date|after_or_equal:today',
            'delivery_date' => 'nullable|date|after_or_equal:pickup_date',
        ], [
            'pickup_date.required' => 'Yükleme tarihini seçin.',
            'pickup_date.after_or_equal' => 'Yükleme tarihi bugünden önce olamaz.',
            'delivery_date.after_or_equal' => 'Teslimat tarihi, yükleme tarihinden önce olamaz.',
        ]];
    }

    /** Teklif varken de düzenlenebilen alanlar: açık adresler, yükleme yetkilisi, şoföre not. */
    protected function privateRules(): array
    {
        return [[
            'pickup_address_private' => 'nullable|string|max:1000',
            'delivery_address_private' => 'nullable|string|max:1000',
            'pickup_contact_name' => 'nullable|string|max:120',
            'pickup_contact_phone' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) !== '' && Phone::normalizeContact((string) $value) === null) {
                    $fail('Geçerli bir telefon numarası girin (cep ya da sabit hat).');
                }
            }],
            'notes' => 'nullable|string|max:'.Load::NOTES_MAX,
        ], [
            'notes.max' => 'Şoföre not en çok '.Load::NOTES_MAX.' karakter olabilir.',
        ]];
    }

    /** @return array{0: array, 1: array} */
    protected function cargoRules(): array
    {
        return [[
            'goods_type' => ['required', Rule::in(Load::GOODS_TYPES)],
            'vehicle_type' => ['required', Rule::in(array_keys(DriverVehicle::getVehicleTypes()))],
            'body_types' => ['array'],
            'body_types.*' => [Rule::in($this->bodyOptions())],
            'load_kind' => ['required', Rule::in(array_keys(BodyTypes::LOAD_KINDS))],
            'weight' => 'required|integer|min:1|max:100000',
            'volume' => 'nullable|integer|min:0|max:10000',
            'e_irsaliye_no' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:'.Load::NOTES_MAX,
        ], [
            'weight.required' => 'Lütfen tahmini ağırlığı girin.',
            'weight.integer' => 'Ağırlık tam sayı (kg) olmalıdır.',
            'notes.max' => 'Şoföre not en çok '.Load::NOTES_MAX.' karakter olabilir.',
        ]];
    }

    /** @return array{0: array, 1: array} */
    protected function priceRules(float $minPrice): array
    {
        return [[
            'price' => 'required|numeric|min:'.$minPrice.'|max:10000000',
        ], [
            'price.required' => 'Lütfen navlun bedelini belirtin.',
            'price.min' => 'Navlun bedeli en az '.number_format($minPrice, 0, ',', '.').' ₺ olmalıdır.',
        ]];
    }

    /** Servise giden veri (LoadService::publish / update). */
    protected function formPayload(): array
    {
        return [
            'pickup_province_code' => $this->pickup_province_code,
            'pickup_district' => $this->pickup_district,
            'pickup_address_private' => $this->pickup_address_private,
            'pickup_contact_name' => $this->pickup_contact_name,
            'pickup_contact_phone' => $this->pickup_contact_phone,
            'delivery_province_code' => $this->delivery_province_code,
            'delivery_district' => $this->delivery_district,
            'delivery_address_private' => $this->delivery_address_private,
            'pickup_date' => Carbon::parse($this->pickup_date)->startOfDay(),
            'delivery_date' => $this->delivery_date !== '' ? Carbon::parse($this->delivery_date)->endOfDay() : null,
            'vehicle_type' => $this->vehicle_type,
            'body_types' => $this->body_types,
            'load_kind' => $this->load_kind,
            'goods_type' => $this->goods_type,
            'weight' => $this->weight,
            'volume' => $this->volume,
            'notes' => $this->notes,
            'price' => (float) $this->price,
            'e_irsaliye_no' => trim($this->e_irsaliye_no) !== '' ? trim($this->e_irsaliye_no) : null,
        ];
    }

    /** Düzenleme ekranı: ilanın alanlarını forma yükler. */
    protected function fillFromLoad(Load $load): void
    {
        $this->pickup_province_code = $load->pickup_province_code ? (string) $load->pickup_province_code : '';
        $this->pickup_district = (string) ($load->pickup_district ?? '');
        $this->pickup_address_private = (string) ($load->pickup_address_private ?? '');
        $this->pickup_contact_name = (string) ($load->pickup_contact_name ?? '');
        $this->pickup_contact_phone = $load->pickup_contact_phone ? Phone::format($load->pickup_contact_phone) : '';
        $this->delivery_province_code = $load->delivery_province_code ? (string) $load->delivery_province_code : '';
        $this->delivery_district = (string) ($load->delivery_district ?? '');
        $this->delivery_address_private = (string) ($load->delivery_address_private ?? '');
        $this->pickup_date = $load->pickup_date?->format('Y-m-d') ?? '';
        $this->delivery_date = $load->delivery_date?->format('Y-m-d') ?? '';
        $this->goods_type = (string) $load->goods_type;
        $this->vehicle_type = (string) $load->vehicle_type;
        $this->body_types = BodyTypes::clean($load->body_types ?? []);
        $this->load_kind = in_array($load->load_kind, array_keys(BodyTypes::LOAD_KINDS), true) ? $load->load_kind : 'komple';
        $this->weight = $load->weight !== null ? (string) $load->weight : '';
        $this->volume = $load->volume !== null ? (string) $load->volume : '';
        $this->e_irsaliye_no = (string) ($load->e_irsaliye_no ?? '');
        $this->notes = (string) ($load->notes ?? '');
        $this->price = $load->price !== null ? (string) (float) $load->price : '';
    }

    /** Herkese açık rota önizlemesi ("Ankara Yenimahalle → İzmir Aliağa"). */
    public function routePreview(string $side): string
    {
        if ($this->{$side.'_province_code'} === '') {
            return '—';
        }
        $province = TurkishLocations::province((int) $this->{$side.'_province_code'});

        return $province ? (string) TurkishLocations::label(['province' => $province['name'], 'district' => $this->{$side.'_district'} ?: null]) : '—';
    }

    /** Adres defteri seçenekleri (yükleme / teslimat). */
    protected function savedAddressOptions(): array
    {
        $addresses = SavedAddress::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderBy('title')
            ->get();

        return [
            'pickupAddresses' => $addresses->whereIn('type', ['pickup', 'both'])->values(),
            'deliveryAddresses' => $addresses->whereIn('type', ['delivery', 'both'])->values(),
        ];
    }
}
