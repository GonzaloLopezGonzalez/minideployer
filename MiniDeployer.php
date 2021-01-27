<?php
require __DIR__ . '/vendor/autoload.php';
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

class MiniDeployer
{
  private $serverData = [];
  private $gitData = [];
  private $mysqlData = [];
  private $command = '';
  private $mysqlFileServerPath = '';
  private $mysqlFileServerName = '';
  private $ssh;
  private $sftp;
  private $composerAction;
  private $projectPath;

  public function __construct()
  {
    $arr_conf = parse_ini_file('config/config.ini', true);
    $this->serverData = $arr_conf['server'];
    $this->gitData = $arr_conf['git'];
    $this->mysqlData = $arr_conf['mysql'];
    $this->mysqlFileServerPath = '/tmp';
    $this->mysqlFileServerName = $this->mysqlFileServerPath . '/database.sql';

    try {
      $this->SSHConnection();
    } catch (Exception $e) {
      echo $e->getMessage();
    }
  }

  public function __destruct()
  {
    $this->ssh->exec('exit;');
  }

  private function SFTPConnection(): bool
  {
    $this->sftp = new SFTP( $this->serverData['ip']);
    if ( !$this->sftp->login( $this->serverData['user'], $this->serverData['password'])) {
      throw new Exception ( 'Unable to connect to server' );
    }

    return true;
  }

  private function SSHConnection(): bool
  {
    $this->ssh = new SSH2( $this->serverData['ip']);
    if ( !$this->ssh->login( $this->serverData['user'], $this->serverData['password']) ) {
      throw new Exception ( 'Unable to connect to server' );
    }

    return true;
  }

  public function deployProject()
  {
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

    echo "Importando base de datos\n";
    $this->importMySqlDatabase();

    echo 'Despliegue finalizado';
  }

  private function importMySqlDatabase()
  {
    $mysqlFileExists = file_exists($this->mysqlData['database_file']);
    if ($mysqlFileExists && $this->SFTPConnection()){
        $this->sftp->chdir($this->mysqlFileServerPath);
        $this->sftp->put('database.sql',file_get_contents($this->mysqlData['database_file']));
        $res = $this->ssh->exec("mysql -h{$this->mysqlData['host']} -r -u {$this->mysqlData['user']} -p{$this->mysqlData['password']} < {$this->mysqlFileServerName}");
    }
    else{
      throw new Exception ( 'Mysql File not exist or unable connect to server' );
    }
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
