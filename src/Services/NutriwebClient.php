<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Encryption;
use App\Repositories\ClientNutriwebSettingsRepository;
use RuntimeException;

/**
 * Client HTTP pour l'API Nutriweb (catalog feed).
 *
 * Auth : `?akey=<clé privée>` en query string (la clé est stockée chiffrée
 * dans client_nutriweb_settings et déchiffrée à l'appel).
 */
final class NutriwebClient
{
    /** Champs demandés au feed catalog (controle ce qu'on receive). */
    private const CATALOG_FIELDS = 'sku,name,brand,price,barcode,size,color,flavor,image,purchase_price';

    /** Suffixe et format de l'URL d'image construite : main-w1000h1000@2x.{version}.jpg */
    private const IMAGE_SIZE_SUFFIX = '-w1000h1000@2x';
    private const IMAGE_EXT = '.jpg';

    public function __construct(
        private readonly string $clientId,
    ) {
    }

    /**
     * @return array{configured:bool, message?:string}
     */
    public function status(): array
    {
        $settings = (new ClientNutriwebSettingsRepository())->get($this->clientId);
        if ($settings['catalogue_url'] === '') {
            return ['configured' => false, 'message' => 'URL du catalogue manquante.'];
        }
        if ($settings['private_key_encrypted'] === null) {
            return ['configured' => false, 'message' => 'Clé privée manquante.'];
        }
        return ['configured' => true];
    }

    /**
     * Récupère le catalogue complet et l'aplatit en lignes (1 ligne = 1 variant).
     *
     * @return list<array{
     *     sku:string, name:string, brand:string, barcode:string,
     *     size:?string, size_rank:?int, color:?string, flavor:?string,
     *     price_base:?float, price_selling:?float, price_retail:?float,
     *     purchase_price:?float, image_url:?string, permalink:?string,
     * }>
     */
    public function fetchCatalog(): array
    {
        $settings = (new ClientNutriwebSettingsRepository())->get($this->clientId);
        if ($settings['catalogue_url'] === '' || $settings['private_key_encrypted'] === null) {
            throw new RuntimeException('Configuration Nutriweb incomplète (URL du catalogue ou clé privée manquante).');
        }

        $key = Encryption::decrypt($settings['private_key_encrypted']);
        $url = $settings['catalogue_url']
            . (str_contains($settings['catalogue_url'], '?') ? '&' : '?')
            . 'akey=' . urlencode($key)
            . '&fields=' . urlencode(self::CATALOG_FIELDS);

        $body = $this->get($url);

        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload['catalog']) || !is_array($payload['catalog'])) {
            throw new RuntimeException('Réponse Nutriweb invalide : clé "catalog" introuvable.');
        }

        $rows = [];
        foreach ($payload['catalog'] as $product) {
            if (!is_array($product)) continue;
            $name = (string) ($product['name'] ?? '');
            $permalink = isset($product['permalink']) ? (string) $product['permalink'] : null;
            $brand = '';
            if (isset($product['brand']) && is_array($product['brand'])) {
                $brand = (string) ($product['brand']['label'] ?? '');
            } elseif (isset($product['brand'])) {
                $brand = (string) $product['brand'];
            }

            $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
            if ($variants === []) {
                $rows[] = [
                    'sku' => '',
                    'name' => $name,
                    'brand' => $brand,
                    'barcode' => '',
                    'size' => null,
                    'size_rank' => null,
                    'color' => null,
                    'flavor' => null,
                    'price_base' => null,
                    'price_selling' => null,
                    'price_retail' => null,
                    'purchase_price' => null,
                    'image_url' => null,
                    'permalink' => $permalink,
                ];
                continue;
            }
            foreach ($variants as $variant) {
                if (!is_array($variant)) continue;
                $price = is_array($variant['price'] ?? null) ? $variant['price'] : [];
                $rows[] = [
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'name' => $name,
                    'brand' => $brand,
                    'barcode' => (string) ($variant['barcode'] ?? ''),
                    'size' => self::pickLabel($variant['size'] ?? null),
                    'size_rank' => self::pickRank($variant['size'] ?? null),
                    'color' => self::pickLabel($variant['color'] ?? null),
                    'flavor' => self::pickLabel($variant['flavor'] ?? null),
                    'price_base' => isset($price['base']['value']) ? (float) $price['base']['value'] : null,
                    'price_selling' => isset($price['selling']['value']) ? (float) $price['selling']['value'] : null,
                    'price_retail' => isset($price['retail']['value']) ? (float) $price['retail']['value'] : null,
                    'purchase_price' => isset($variant['purchase_price']) ? (float) $variant['purchase_price'] : null,
                    'image_url' => self::buildImageUrl($variant['image'] ?? null),
                    'permalink' => $permalink,
                ];
            }
        }
        return $rows;
    }

    /**
     * Extrait le label d'un attribut variant (size/color/flavor) qui peut être
     * un objet {id, label, ...}, une string ou null.
     */
    private static function pickLabel(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_array($value) && isset($value['label'])) {
            $label = trim((string) $value['label']);
            return $label === '' ? null : $label;
        }
        if (is_string($value)) {
            $label = trim($value);
            return $label === '' ? null : $label;
        }
        return null;
    }

    /**
     * Extrait le rank numerique d'un attribut variant (utilise par size pour
     * trier 90g < 1820g correctement, vs un sort alphabetique qui mettrait
     * "1820g" avant "90g").
     */
    private static function pickRank(mixed $value): ?int
    {
        if (is_array($value) && isset($value['rank'])) {
            return (int) $value['rank'];
        }
        if (is_array($value) && isset($value['value'])) {
            return (int) $value['value'];
        }
        return null;
    }

    /**
     * Construit l'URL complete de l'image principale depuis l'objet image Nutriweb :
     *   {url}{path}{filename}-w1000h1000@2x.{version}.jpg
     *
     * Ex: {"url":"https://www.nutrimeo.com","path":"/img/prods/1004/","filename":"main","version":"08262"}
     *  -> https://www.nutrimeo.com/img/prods/1004/main-w1000h1000@2x.08262.jpg
     */
    private static function buildImageUrl(mixed $image): ?string
    {
        if (!is_array($image)) return null;
        $url = trim((string) ($image['url'] ?? ''));
        $path = (string) ($image['path'] ?? '');
        $filename = trim((string) ($image['filename'] ?? ''));
        $version = trim((string) ($image['version'] ?? ''));
        if ($url === '' || $filename === '' || $version === '') return null;
        return rtrim($url, '/') . $path . $filename . self::IMAGE_SIZE_SUFFIX . '.' . $version . self::IMAGE_EXT;
    }

    private function get(string $url): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];

        // CA bundle : on tente le bundle Mozilla embarqué par composer/ca-bundle.
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $caPath = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($caPath)) {
                $options[CURLOPT_CAINFO] = $caPath;
            }
        }

        // Opt-in dev local : désactivation TLS verify (cf. quirk Avast).
        $tlsVerify = $_ENV['APP_TLS_VERIFY'] ?? 'true';
        if (filter_var($tlsVerify, FILTER_VALIDATE_BOOLEAN) === false) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erreur réseau Nutriweb : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 200);
            throw new RuntimeException("Nutriweb a renvoyé HTTP {$httpCode} : {$snippet}");
        }
        return (string) $body;
    }
}
