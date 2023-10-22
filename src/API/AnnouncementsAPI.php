<?php

use Infinex\Exceptions\Error;

class AnnouncementsAPI {
    private $log;
    private $anno;
    
    function __construct($log, $anno) {
        $this -> log = $log;
        $this -> anno = $anno;
        
        $this -> log -> debug('Initialized announcements API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/', [$this, 'getAnnouncements']);
        $rc -> get('/{annoid}', [$this, 'getAnnouncement']);
    }
    
    public function getAnnouncements($path, $query, $body, $auth) {
        $resp = $this -> anno -> getAnnouncements([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$queryp['limit']
        ]);
        
        foreach($resp['announcements'] as $k => $v)
            $resp['announcements'][$k] = $this -> ptpAnnouncement($v, false);
        
        return $resp;
    }
    
    public function getAnnouncement($path, $query, $body, $auth) {
        $anno = $this -> anno -> getAnnouncement([
            'annoid' => $path['annoid']
        ]);
        
        if(!$anno['enabled'])
            throw new Error('FORBIDDEN', 'No permissions to announcement '.$path['annoid']);
        
        return $this -> ptpAnnouncement($anno, isset($query['full']));
    }
    
    private function ptpAnnouncement($record, $full) {
        $resp = [
            'annoid' => $record['annoid'],
            'time' => $record['time'],
            'path' => $record['path'],
            'title' => $record['title'],
            'excerpt' => $record['excerpt'],
            'featuredImg' => $record['featuredImg'],
            'readMore' => $record['body'] !== null
        ];
        
        if($full)
            $resp['body'] = $record['body'];
        
        return $resp;
    }
}

?>