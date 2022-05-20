<?php

declare( strict_types = 1 );

require_once __DIR__ . '/PostgresDatabase.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Platform\SocketAddress;
use PositivelyCharged\PinkyFloidDb\PinkyFloidDatabase;
use Service\Console\PlayerInfoParser;
use Service\Console\Response;
use Service\Event\EventService;
use Service\Console\Console;
use Service\Console\ConsoleService;
use Service\Console\CaptureRequest;
use Service\Plugin\Plugin;
use Service\Plugin\PluginService;
use Service\Plugin\Request;
use Service\Plugin\Command;
use Service\Server\ServerService;

class ServerDetails {
   public string $hostname;
   public string $map;
   public string $ip;
   public int $port;
   public int $maxClients;
   public int $maxPlayers;
   public array $players;
}

class PinkyFloidPlugin extends Plugin {
   private PinkyFloidDatabase $db;

   public function __construct(
      PluginService $service,
      private ConsoleService $consoles,
      private ServerService $servers,
      EventService $events ) {
      parent::__construct( $service );
      $this->db = PinkyFloidDatabase::connect();
      $events->listen( 'start', function( Bot $bot ) use ( $events ) {
         $this->updateServerDetails();
         $events->listen( 'new-map', function() {
            $this->updateServerDetails();
         } );
      } );
      $events->listen( 'server-acquired', function( $event ) {
         $this->updateServerDetails();
      } );

      $events->listen( 'se-pb', function( Console $console,
         array $args ): void {
         $this->handlePbEvent( $console, $args );
      } );

      $events->listen( 'se-solo-record', function( Console $console,
         array $args ): void {
         $this->handleSoloRecordEvent( $console, $args );
      } );
   }

   private function updateServerDetails(): void {
      $addr = new SocketAddress( '127.0.0.1', 10666 );
      $console = $this->consoles->getConsoleByAddress( $addr );
      if ( $console !== null ) {
         $request = new CaptureRequest( $console );
         $request->add( 'sv_hostname' );
         $request->add( 'sv_maxclients' );
         $request->add( 'sv_maxplayers' );
         $request->separate( 'player_data' );
         $request->add( 'playerinfo' );
         $request->execute( function( $output ) use ( $console ) {
            $details = $this->readServerDetails( $console, $output );
            $this->storeServerDetails( $details );
            var_dump( $details );
            //$bot->terminate();
         } );
         /*
         $command = 'sv_hostname; sv_website; dmflags; printstats; wads';
         $console->executeAndCapture( $command, function( $output ) {
            var_dump( $output );
            $response = new Response( $output );
         }, false ); */
      }
   }

   private function readServerDetails( Console $console,
      array $requests ): ServerDetails {
      $response = new Response( $requests );
      $details = new ServerDetails();
      $details->hostname = $response->readVar( 'sv_hostname' );
      $details->map = $console->getMap();
      $details->maxClients = ( int ) $response->readVar( 'sv_maxclients' );
      $details->maxPlayers = ( int ) $response->readVar( 'sv_maxplayers' );
      $address = $console->getAddress();
      $details->ip = $address->getIp();
      $details->port = $address->getPort();
      $details->players = $response->readPlayerInfo();
      return $details;
   }

   private function storeServerDetails( ServerDetails $details ): void {
      $addr = sprintf( '%s:%s', $details->ip, $details->port );
      $this->db->execute( 'DELETE FROM server' );
      $this->db->execute( 'DELETE FROM player WHERE server_addr = :addr',
         [ ':addr' => $addr ] );
      $this->servers->forEachServer( function( $server ) use( $details ) {
         $this->db->execute( 'INSERT INTO server VALUES ' .
            '( :name, :map, :player_count, :max_players, :ip, :port, :status )',
            [ ':name' => $details->hostname,
               ':map' => $details->map,
               ':player_count' => count( $details->players ),
               ':max_players' => $details->maxClients,
               ':ip' => $details->ip,
               ':port' => $details->port,
               ':status' => 0,
            ] );
      } );
      foreach ( $details->players as $player ) {
         $this->db->execute( 'INSERT INTO player'.
            '( ip, port, server_addr, name, spec ) VALUES' .
            '( :ip, :port, :server_addr, :name, :spec )', [
               ':ip' => $player[ 'ip' ],
               ':port' => $player[ 'port' ],
               ':server_addr' => $addr,
               ':name' => $player[ 'name' ],
               ':spec' => $player[ 'spec' ] ? 'true' : 'false',
            ]
         );
      }
      printf( "refreshing database with new server data\n" );
   }

   #[ Command( name: 'pf-update-server-details', group: GROUP_HEADADMIN ) ]
   public function onUpdateServerDetails( Request $request ): void {
      $this->updateServerDetails();
   }

   #[ Command( name: 'pf-command', group: GROUP_HEADADMIN ) ]
   public function onCommand( Request $request ): void {
      $addr = new SocketAddress( '127.0.0.1', 10666 );
      $console = $this->consoles->getConsoleByAddress( $addr );
      if ( $console !== null ) {
         $command = join( ' ', $request->args );
         $console->execute( $command );
      }
   }

   private function handlePbEvent( Console $console, array $args ): void {
      printf( "pb\n" );
      var_dump( $args );
      $client = $console->getClientByNumber( ( int ) $args[ 'player' ] );
      if ( $client !== null ) {
         var_dump( $console->getMap() );
         var_dump( ( int ) $args[ 'time' ] );
         var_dump( $client->getName() );
         $this->db->addPb( $console->getMap(), ( int ) $args[ 'time' ], null,
            $client->getName() );
      }
   }

   private function handleSoloRecordEvent( Console $console,
      array $args ): void {
      printf( "solo record\n" );
      var_dump( $args );
   }
}
