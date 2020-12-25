<?php
require __DIR__ . '/vendor/autoload.php';
use phpseclib3\Net\SSH2;

class MiniDeployer
{
  private $serverData = [];
  private $gitData = [];
  private $command = '';
  private $ssh;
  private $composerAction;
  private $projectPath;

  public function __construct()
  {
    $arr_conf = parse_ini_file('config/config.ini', true);
    $this->serverData = $arr_conf['server'];
    $this->gitData = $arr_conf['git'];

    try {
      $this->serverConnection();
    } catch (Exception $e) {
      echo $e->getMessage();
    }
  }

  public function __destruct()
  {
    $this->ssh->exec('exit;');
  }

  private function serverConnection(): bool
  {
    $this->ssh = new SSH2( $this->serverData['ip']);
    if ( !$this->ssh->login( $this->serverData['user'], $this->serverData['password']) ) {
      throw new Exception ( 'Unable to connect to server' );
    }

    return true;
  }

  public function deployProject()
  {
    echo "Desplegando proyecto\n";
    $deployPathExists = $this->checkPathExists($this->serverData['deploy-path']);
    if (false !== strpos($deployPathExists,'No such file or directory')){
        $this->createDeployPath();
    }
    $this->command .= 'cd '.$this->serverData['deploy-path'] . '; ';
    $this->deployFromGit();
    echo "Conectando con github\n";
    $this->ssh->exec($this->command);
    echo "Instalando dependencias\n";
    $this->installDependencies();
  }

  private function installDependencies()
  {
    $deployPathExists = $this->checkPathExists($this->projectPath.'/composer.json');
    if (false === strpos($deployPathExists,'No such file or directory')){
       $command = 'cd '.$this->projectPath.';';
       $command .= $this->composerAction;
       $command .= '  --verbose --prefer-dist --no-interaction --optimize-autoloader';
       if ('prod' === $this->serverData['env']){
          $command .= '  --no-dev';
       }
      $this->ssh->exec($command);
    }
  }

  private function checkPathExists(string $path): string
  {
    return $this->ssh->exec('cd '.$path);
  }

  private function createDeployPath()
  {
    $this->command = 'mkdir '.$this->serverData['deploy-path'] . ';';
  }

  private function gitPull()
  {
    $this->command .= 'git pull;';
    $this->composerAction = 'composer update';
  }

  private function gitClone()
  {
    $command = 'git clone';
    if (!empty($this->gitData['branch'])){
      $command .= ' -b '.$this->gitData['branch'];
    }
    $command .= ' '.$this->gitData['repository'];
    $this->command .= $command.';';
    $this->composerAction = 'composer install';
  }

  private function deployFromGit()
  {
    $arrRepository = explode('/',$this->gitData['repository']);
    $this->projectPath = $this->serverData['deploy-path'].$arrRepository[count($arrRepository)-1];
    $deployPathExists =  $this->checkPathExists($this->projectPath);
    if (false === strpos($deployPathExists,'No such file or directory')){
        $this->gitPull();
    }else{
       $this->gitClone();
    }
  }
}
