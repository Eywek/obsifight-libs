<?php
class Server {

  protected $_config = [];

  public function __construct($key, $ip, $port)
  {
    $this->_config = [
      'key' => $key,
      'ip' => $ip,
      'port' => $port,
      'timeout' => 1
    ];
  }

  /*
    Request
  */
  protected function getEndpoint()
  {
    return 'http://' . $this->_config['ip'] . ':' . $this->_config['port'];
  }

  protected function makeURL($endpoint, $data)
  {
    return $endpoint . '?key=' . $this->_config['key'] . '&' . implode('&', array_map(function ($v, $k) {
        return sprintf("%s=%s", $k, rawurlencode($v));
      }, $data, array_keys($data))
    );
  }

  public function call(array $methods)
  {
    $endpoint = $this->getEndpoint();
    $url = $this->makeURL($endpoint, $methods);

    list($body, $code, $error) = $this->request($url);

    if ($code === 0 || $error)
      return new InternalConnectionErrorException('cURL error #'.$error);
    else if ($code === 500)
      return new InternalServerErrorException($body, $code);
    else if ($code !== 200 || @json_decode($body) == false)
      return new RequestErrorException($body, $code);
    else
      return json_decode($body, true);
  }

  public function request($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_COOKIESESSION, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, $this->_config['timeout']);

    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    return array($body, $code, $error);
  }

  /*
    Encryption - Decryption
  */
  protected function pkcs5_pad($text, $blocksize)
  {
    $pad = $blocksize - (strlen($text) % $blocksize);
    return $text . str_repeat(chr($pad), $pad);
  }

  protected function pkcs5_unpad($text)
  {
    $pad = ord($text{strlen($text)-1});
    if ($pad > strlen($text)) return false;
    if (strspn($text, chr($pad), strlen($text) - $pad) != $pad) return false;
    return substr($text, 0, -1 * $pad);
  }

  protected function encryptWithKey($data)
  {
    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
    $data = $this->pkcs5_pad($data, mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC));
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $signed = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_128, substr($this->_config['key'], 0, 16), $data, MCRYPT_MODE_CBC, $iv));
    return array('signed' => $signed, 'iv' => base64_encode($iv));
  }

  protected function decryptWithKey($data, $iv)
  {
    return $this->pkcs5_unpad(mcrypt_decrypt(MCRYPT_RIJNDAEL_128, substr($this->_config['key'], 0, 16), base64_decode($data), MCRYPT_MODE_CBC, base64_decode($iv)));
  }

}

class Methods extends Server {

  protected $_methods = [];
  protected $_error;

  public function isConnected($username)
  {
    $this->_methods['isConnected'] = ['isConnected' => $username];
    return $this;
  }
  public function isOnline()
  {
    $req = $this->call(['getPlayerMax' => 'server']);
    return !($req instanceof Exception);
  }
  public function playerCount()
  {
    $this->_methods['playerCount'] = ['getPlayerCount' => 'server'];
    return $this;
  }
  public function playerMax()
  {
    $this->_methods['playerMax'] = ['getPlayerMax' => 'server'];
    return $this;
  }
  public function sendCommand($command)
  {
    $this->_methods['sendCommand'] = ['performCommand' => $command];
    return $this;
  }

  public function get()
  {
    $methods = [];
    foreach($this->_methods as $name => $method) {
      $methods += $method;
    }

    $req = $this->call($methods);
    if ($req instanceof Exception) { // Return false to all
      $this->_error = $req;
      $false = [];
      for ($i=0; $i < count($this->_methods); $i++) {
        $false[] = false;
      }
      return array_combine(array_keys($this->_methods), $false);
    }

    // Set values with function name + replace bool
    return array_map(function ($data) {
      if ($data == 'true' || $data == 'TRUE') return true;
      if ($data == 'false' || $data == 'FALSE') return false;
      if ((string)(int)$data == $data) return intval($data);
      return $data;
    }, array_combine(array_keys($this->_methods), $req));
  }

  public function getError()
  {
    return $this->_error;
  }
}
