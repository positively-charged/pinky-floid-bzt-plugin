<?php

declare( strict_types = 1 );

class PostgresDatabase extends PDO {
   public function __construct( string $dsn ) {
      parent::__construct( $dsn );
   }

   public function execute( string $query, array $args = [] ): bool {
      if ( ( $stmt = $this->prepare( $query ) ) !== false ) {
         return $stmt->execute( $args );
      }
      return false;
   }

   public function fetchRow( string $query, array $args = [] ): array {
      if ( ( $stmt = $this->prepare( $query ) ) !== false ) {
         if ( $stmt->execute( $args ) ) {
            return $stmt->fetch( PDO::FETCH_ASSOC );
         }
      }
      return [];
   }

   public function fetchAll( string $query, array $args = [] ): array {
      if ( ( $stmt = $this->prepare( $query ) ) !== false ) {
         if ( $stmt->execute( $args ) ) {
            return $stmt->fetchAll( PDO::FETCH_ASSOC );
         }
      }
      return [];
   }

   public static function connect(): PostgresDatabase {
      $dbName = DB_NAME;
      $dbName = DB_NAME . '-dev';
      $dsn = sprintf( 'pgsql:host=%s;dbname=%s;user=%s', DB_ADDR, $dbName,
         DB_USERNAME );
      $dbh = new PostgresDatabase( $dsn );
      return $dbh;
   }
}
