<?php

namespace App\Jobs;

use App\Configurations\IbmWatsonConfiguration;
use App\Services\FileOpenerService;
use App\Traits\InteractsWithLocalFileSystem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Dispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TranscribeAudioFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithLocalFileSystem;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var string
     */
    private $oldFilename;

    /**
     * Create a new job instance.
     *
     * @param string $oldFilename
     * @param string $filename
     */
    public function __construct(string $oldFilename, string $filename)
    {
        $this->filename = $filename;
        $this->oldFilename = $oldFilename;
    }

    /**
     * @param IbmWatsonConfiguration $ibmWatsonConfiguration
     * @param Client                 $httpClient
     * @param Dispatcher             $commandDispatcher
     * @param FileOpenerService      $fileOpenerService
     *
     * @throws GuzzleException
     */
    public function handle(
        IbmWatsonConfiguration $ibmWatsonConfiguration,
        Client $httpClient,
        Dispatcher $commandDispatcher,
        FileOpenerService $fileOpenerService
    ) {
        $response = $httpClient->request(
            Request::METHOD_POST,
            $ibmWatsonConfiguration->getApiEndpoint(),
            [
                'auth' => [
                    $ibmWatsonConfiguration->getUsername(),
                    $ibmWatsonConfiguration->getPassword(),
                ],
                'headers' => [
                    'Content-Type' => 'audio/flac',
                ],
                'body' => $fileOpenerService->openForReading($this->getFilePath($this->filename)),
            ]
        );

        $jsonResult = \json_decode(\trim($response->getBody()->getContents()));

        $output = \array_map(function ($result) {
            return \trim(
                \ucfirst(\str_replace('%HESITATION', '...', $result->alternatives[0]->transcript))
            );
        }, $jsonResult->results);

        $commandDispatcher->dispatch(new ProcessTranscribedText($output, $this->oldFilename));
    }
}
