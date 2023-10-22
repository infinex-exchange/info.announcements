<?php

require __DIR__.'/Announcements.php';

require __DIR__.'/API/AnnouncementsAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $anno;
    
    private $annoApi;
    private $rest;
    
    function __construct() {
        parent::__construct('info.announcements');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> anno = new Announcements(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> annoApi = new AnnouncementsAPI(
            $this -> log,
            $this -> anno
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> annoApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> anno -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $th -> rest -> stop() -> then(
            function() use($th) {
                return $th -> anno -> stop();
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>