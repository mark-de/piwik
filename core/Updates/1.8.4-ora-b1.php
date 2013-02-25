<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Piwik_Updates_1_8_4_ora-b1
 * Resetting some unneccessary not null-constraints 
 * MySQL is far less strict than Oracle 
 * we cannot insert "" into Oracle tables if 
 * cols are not nullable!!
 * @author Ancud-IT GmbH 
 */
class Piwik_Updates_1_8_4_ora_b1 extends Piwik_Updates {
    
     

     public static function getSql( $schema = 'Myisam' )
     {
         $tablesToMod = array( 
                    Piwik_Common::prefixTable('site') 
                            => array( 'excluded_ips', 'excluded_parameters', 'group' ),
                    Piwik_Common::prefixTable('goal') 
                            => array( 'pattern', 'pattern_type'),
                    Piwik_Common::prefixTable('log_visit') 
                            => array('location_browser_lang')
                 );
         
         $aSql = array();
         
         foreach( $tablesToMod as $table => $fields )
         {
             $sql = 'ALTER TABLE ' . $table . ' MODIFY (';
             
             for( $i = 0; $i < count($fields); $i++ )
             {
                 $sql .= $fields[$i] . ' NULL';
                 $sql .= $i < count($fields) - 1 ? ', ' : '';
             }
             
             $sql .= ')';  
             
             $aSql[$sql] = false;
         }
             
         return $aSql;
     }

     public static function isMajorUpdate()
     {
         return false;
     }

     public static function update()
     {
         Piwik_Updater::updateDatabase(__FILE__, self::getSql());
     }

     
    
}
?>
