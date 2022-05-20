<?php

declare( strict_types = 1 );

class RecordResponse {
   public function __construct(
      /** @var PersonalBest[] */
      public array $pbs,
      /** @var SoloRecord[] */
      public array $soloRecords,
      /** @var TeamRecord[] */
      public array $teamRecords,
   ) {}
}

class PersonalBest {
   public int $runId;

   public function __construct(
      public string $map,
      public string $username,
      public int $finishTime,
      public int $date,
   ) {
      $this->runId = 0;
   }
}

class SoloRecord {
   public string $map;
   public string $alias;
   public int $finishTime;
   public int $date;
   public ?PersonalBest $pb;

   public function __construct() {
      $this->map = '';
      $this->alias = '';
      $this->finishTime = 0;
      $this->date = 0;
      $this->pb = null;
   }
}

class TeamRecord {
   public string $map;
   public int $finishTime;
   public int $date;
   /**
    * NOTE: This field contains the true count of the number of helpers. Do not
    * use count() on the helpers array to determine the number of helpers. The
    * helpers array might contain helpers from a previous record; that is, the
    * scripts do not delete the previous helpers, they just overwrite them.
    */
   public int $totalHelpers;
   /** @var Helper[] */
   public array $helpers;

   public function __construct() {
      $this->map = '';
      $this->finishTime = 0;
      $this->date = 0;
      $this->totalHelpers = 0;
      $this->helpers = [];
   }
}

class Helper {
   public string $alias;
   public int $points;

   public function __construct() {
      $this->alias = '';
      $this->points = 0;
   }
}

class RecordExporter {
   /** @var PersonalBest[] */
   private array $pbs;
   /** @var SoloRecord[] */
   private array $soloRecords;
   /** @var TeamRecord[] */
   private array $teamRecords;

   private function __construct( private SQLite3 $db ) {
      $this->pbs = [];
      $this->soloRecords = [];
      $this->teamRecords = [];
   }

   public static function create( string $sqliteDbPath ): RecordExporter {
      try {
         $db = new SQLite3( $sqliteDbPath, SQLITE3_OPEN_READONLY );
         return new RecordExporter( $db );
      }
      catch ( Exception $err ) {
         printf( "failed to open database: %s\n", $sqliteDbPath );
         exit( 1 );
      }
   }

   public function fetchRecords(): RecordResponse {
      return $this->fetchRecordsFromDate( 0 );
   }

   public function fetchRecordsFromDate( int $startDate ): RecordResponse {
      $this->pbs = [];
      $this->soloRecords = [];
      $this->teamRecords = [];

      $stmt = $this->db->prepare( 'select * from Zandronum where ' .
         'CAST( Timestamp as INT ) > :start_date' );
      $stmt->bindValue( ':start_date', $startDate, SQLITE3_INTEGER );
      $result = $stmt->execute();
      $total = 0;
      while ( ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {
         $this->parseRow( $row );
         ++$total;
      }

      $this->associatePbsWithRecords();

      return new RecordResponse( $this->pbs, $this->soloRecords,
         $this->teamRecords );
   }

   private function parseRow( array $row ): void {
      if ( str_ends_with( $row[ 'Namespace' ], '_pbs' ) ) {
         $this->parsePb( $row );
      }
      else if ( str_starts_with( $row[ 'KeyName' ], 'jrs_' ) ) {
         $this->parseSoloRecordField( $row );
      }
      else if ( str_starts_with( $row[ 'KeyName' ], 'jrt_' ) ) {
         $this->parseTeamRecordField( $row );
      }
      else if ( str_starts_with( $row[ 'KeyName' ], 'JMR' ) ) {

      }
      else {
         switch ( $row[ 'Namespace' ] ) {
         case 'JSFMembers':
         case 'SSMembers':
            // Not sure what these are, but they were found in the firestick
            // records database file. Ignore for now.
            break;
         default:
            printf( "unknown row: \n" );
            var_dump( $row );
         }
      }
   }

   private function parsePb( array $row ): void {
      if ( preg_match( '/(?<map>.+)_pbs/', $row[ 'Namespace' ],
         $matches ) === 1 ) {
         $pb = new PersonalBest(
            $matches[ 'map' ],
            $row[ 'KeyName' ],
            ( int ) $row[ 'Value' ],
            ( int ) $row[ 'Timestamp' ],
         );
         array_push( $this->pbs, $pb );
      }
   }

   private function parseSoloRecordField( array $row ): void {
      $map = $row[ 'Namespace' ];
      if ( ! array_key_exists( $map, $this->soloRecords ) ) {
         $record = new SoloRecord();
         $record->map = $map;
         $record->date = ( int ) $row[ 'Timestamp' ];
         $this->soloRecords[ $map ] = $record;
      }

      $record = $this->soloRecords[ $map ];

      switch ( $row[ 'KeyName' ] ) {
      case 'jrs_hs_author':
         $record->alias = $row[ 'Value' ];
         break;
      case 'jrs_hs_time':
         $record->finishTime = ( int ) $row[ 'Value' ];
         break;
      case 'jrs_hs_rdate':
         break;
      }
   }

   private function parseTeamRecordField( array $row ): void {
      $map = $row[ 'Namespace' ];
      if ( ! array_key_exists( $map, $this->teamRecords ) ) {
         $record = new TeamRecord();
         $record->map = $map;
         $record->date = ( int ) $row[ 'Timestamp' ];
         $this->teamRecords[ $map ] = $record;
      }

      $record = $this->teamRecords[ $map ];

      if ( str_starts_with( $row[ 'KeyName' ], 'jrt_hs_helper' ) ) {
         $this->parseHelperAlias( $row, $record );
      }
      else if ( str_starts_with( $row[ 'KeyName' ], 'jrt_hs_points' ) ) {
         $this->parseHelperPoints( $row, $record );
      }
      else {
         switch ( $row[ 'KeyName' ] ) {
         case 'jrt_hs_time':
            $record->finishTime = ( int ) $row[ 'Value' ];
            break;
         case 'jrt_hs_total_players':
            $record->totalHelpers = ( int ) $row[ 'Value' ];
            break;
         case 'jrt_hs_rdate':
            break;
         default:
            printf( "unknown team record field: %s\n", $row[ 'KeyName' ] );
         }
      }
   }

   private function parseHelperAlias( array $row, TeamRecord $record ): void {
      if ( preg_match( '/jrt_hs_helper_(?<helper>[0-9]+)/', $row[ 'KeyName' ],
         $matches ) === 1 ) {
         $helper = $this->getHelper( $record, ( int ) $matches[ 'helper' ] );
         $helper->alias = $row[ 'Value' ];
      }
   }

   private function parseHelperPoints( array $row, TeamRecord $record ): void {
      if ( preg_match( '/jrt_hs_points_(?<helper>[0-9]+)/', $row[ 'KeyName' ],
         $matches ) === 1 ) {
         $helper = $this->getHelper( $record, ( int ) $matches[ 'helper' ] );
         $helper->points = ( int ) $row[ 'Value' ];
      }
   }

   private function getHelper( TeamRecord $record, int $number ): Helper {
      if ( ! array_key_exists( $number, $record->helpers ) ) {
         $record->helpers[ $number ] = new Helper();
      }
      return $record->helpers[ $number ];
   }

   private function associatePbsWithRecords(): void {
      foreach ( $this->soloRecords as $record ) {
         foreach ( $this->pbs as $pb ) {
            if ( $pb->map === $record->map &&
               $pb->finishTime === $record->finishTime ) {
               $record->pb = $pb;
            }
         }
      }
   }
}
