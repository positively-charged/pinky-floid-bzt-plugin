<?php

declare( strict_types = 1 );

class RecordImporter {
   public function __construct( private PostgresDatabase $db ) {

   }

   public static function create(): RecordImporter {
      $db = PostgresDatabase::connect();
      return new RecordImporter( $db );
   }

   public function importRecords( RecordResponse $response ): void {
      foreach ( $response->pbs as $pb ) {
         $this->insertPb( $pb );
      }
      foreach ( $response->soloRecords as $record ) {
         $this->insertSoloRecord( $record );
      }
      foreach ( $response->teamRecords as $record ) {
         $this->insertTeamRecord( $record );
      }
   }

   private function insertPb( PersonalBest $pb ): void {
      $pb->runId = $this->insertRun( $pb->map, $pb->finishTime, $pb->date );
      $this->db->execute( 'INSERT INTO personal_best( run, alias ) ' .
         'VALUES( :run, :alias )', [ ':run' => $pb->runId, ':alias' =>
            $pb->username ] );
   }

   private function insertRun( string $map, int $finishTime, int $date ): int {
      $row = $this->db->fetchRow( 'INSERT INTO run( map, finish_time, date ) ' .
         'VALUES( :map, :finish_time, to_timestamp( :date ) ) returning id',
         [ ':map' => $map, ':finish_time' => $finishTime, ':date' => $date ] );
      if ( ! empty( $row ) ) {
         return $row[ 'id' ];
      }
      return 0;
   }

   private function insertSoloRecord( SoloRecord $record ): void {
      $this->db->execute( 'INSERT INTO record( run ) VALUES ( :run )',
         [ ':run' => $record->pb->runId ] );
   }

   private function insertTeamRecord( TeamRecord $record ): void {
      $runId = $this->insertRun( $record->map, $record->finishTime,
         $record->date );
      for ( $i = 0; $i < $record->totalHelpers; ++$i ) {
         $this->db->execute( 'INSERT INTO helper( run, points, alias ) ' .
            'VALUES( :run, :points, :alias )', [
               ':run' => $runId,
               ':points' => $record->helpers[ $i ]->points,
               ':alias' => $record->helpers[ $i ]->alias,
            ]
         );
      }
   }
}

require_once 'RecordExporter.php';
require_once 'PostgresDatabase.php';

define( 'DB_ADDR', 'localhost' );
define( 'DB_NAME', 'pinkyfloid' );
define( 'DB_USERNAME', 'positron' );

if ( $argc < 2 ) {
   exit( 1 );
}
$exporter = RecordExporter::create( $argv[ 1 ] );
//$records = $exporter->fetchRecordsFromDate( 1628782973 );
$records = $exporter->fetchRecords();

$importer = RecordImporter::create();
$importer->importRecords( $records );
