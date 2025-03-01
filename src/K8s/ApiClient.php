<?php

namespace Core\K8s;

use Analog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use JsonException;

class ApiClient
{

    public const API_URL_SCHEME_NO_NS = '{{API-URL}}/{{API-VERSION}}/{{API-SECTION}}';
    public const API_URL_SCHEME = '{{API-URL}}/{{API-VERSION}}/namespaces/{{NAMESPACE}}/{{API-SECTION}}';
    public const TYPE_STATEFULSET = 'statefulsets';
    public const TYPE_SERVICE = 'services';
    public const TYPE_PODS = 'pods';
    public const TYPE_NODES = 'nodes';
    public const TYPE_CONFIGMAPS = 'configmaps';
    public const TYPE_SECRETS = 'secrets';
    public const TYPE_PVC = 'persistentvolumeclaims';

    public const PROD_MODE = 'prod';

    public const DEV_MODE = 'dev';


    private string $apiUrl = 'https://kubernetes.default.svc';
    private string $cert = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
    private array $apiSections
        = [
            self::TYPE_SERVICE     => 'api/v1',
            self::TYPE_STATEFULSET => 'apis/apps/v1',
            self::TYPE_CONFIGMAPS  => 'api/v1',
            self::TYPE_PVC         => 'api/v1',
            self::TYPE_SECRETS     => 'api/v1',
            self::TYPE_PODS        => 'api/v1',
            self::TYPE_NODES       => 'api/v1',
        ];

    private string $bearer;
    protected Client $httpClient;
    private string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '.
    '(KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36';

    private string $namespace;

    private string $mode = self::PROD_MODE;

    public function __construct()
    {
        $this->bearer    = $this->getBearer();
        $this->namespace  = $this->getNamespace();
        $this->httpClient = new Client();
    }

    /**
     * @throws JsonException
     */
    public function getManticorePods(array $labels = null)
    {
        return json_decode(
            $this->request(self::TYPE_PODS, 'GET', false, $labels)->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function setApiUrl($apiUrl): void
    {
        $this->apiUrl = $apiUrl;
    }

    public function setNamespace($namespace): void
    {
        $this->namespace = $namespace;
    }

    public function setMode($mode): void
    {
        if (in_array($mode, ['prod', 'dev'])) {
            $this->mode = $mode;
        } else {
            throw new \RuntimeException('Wrong mode. Allowed only "prod" and "dev" modes');
        }
    }

    /**
     * @throws JsonException
     */
    public function getNodes()
    {
        return json_decode($this->request(self::TYPE_NODES, 'GET', true)->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function request($section, $type = "GET", $noNamespace = false, array $labels = null)
    {
        $params = [];

        if ($this->mode === self::PROD_MODE){
            $params = [
                'verify'  => $this->cert,
                'version' => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->bearer,
                    'Accept'        => 'application/json',
                    'User-Agent'    => $this->userAgent,
                ],
            ];
        }


        $url = $this->getUrl($section, $noNamespace);
        if ($labels) {
            $labelsCond = [];
            foreach ($labels as $labelName => $labelValue) {
                $labelsCond[] = $labelName."=".$labelValue;
            }

            $url .= '?labelSelector='.implode(",", $labelsCond);
        }

        return $this->call($type, $url, $params);
    }


    private function getUrl($section, $noNamespace = false)
    {
        if ($noNamespace) {
            return str_replace(
                ['{{API-URL}}', '{{API-VERSION}}', '{{API-SECTION}}'],
                [$this->apiUrl, $this->apiSections[$section], $section],
                self::API_URL_SCHEME_NO_NS
            );
        }

        return str_replace(
            ['{{API-URL}}', '{{API-VERSION}}', '{{NAMESPACE}}', '{{API-SECTION}}'],
            [$this->apiUrl, $this->apiSections[$section], $this->namespace, $section],
            self::API_URL_SCHEME
        );
    }


    protected function getBearerPath(): string
    {
        return '/var/run/secrets/kubernetes.io/serviceaccount/token';
    }

    protected function getNamespacePath(): string
    {
        return  '/var/run/secrets/kubernetes.io/serviceaccount/namespace';
    }

    protected function getBearer()
    {
        return $this->readFile($this->getBearerPath());
    }


    protected function getNamespace()
    {
        return $this->readFile($this->getNamespacePath());
    }

    protected function getUserAgent(): string
    {
        return $this->userAgent;
    }

    private function readFile($filename){
        if (file_exists($filename)) {
            return file_get_contents($filename);
        }

        return false;
    }

    public function get($url)
    {
        return $this->call('GET', $url);
    }

    private function call($method, $url, $params = [])
    {
        try {
            return $this->httpClient->request($method, $url, $params);
        } catch (RequestException $e) {
            Analog::log(Psr7\Message::toString($e->getRequest()));

            if ($e->hasResponse()) {
                Analog::log(Psr7\Message::toString($e->getResponse()));
            }

            $this->terminate(1);
        } catch (GuzzleException $e) {
            Analog::log($e->getMessage());
            $this->terminate(1);
        }
        return null;
    }

    protected function terminate($exitStatus){
        exit($exitStatus);
    }

}
