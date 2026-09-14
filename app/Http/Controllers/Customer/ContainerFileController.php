<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BatchContainerPathsRequest;
use App\Http\Requests\Customer\CreateContainerDirectoryRequest;
use App\Http\Requests\Customer\CreateContainerFileRequest;
use App\Http\Requests\Customer\DeleteContainerPathRequest;
use App\Http\Requests\Customer\ExtractContainerArchiveRequest;
use App\Http\Requests\Customer\MoveContainerPathsRequest;
use App\Http\Requests\Customer\RenameContainerPathRequest;
use App\Http\Requests\Customer\SaveContainerFileContentRequest;
use App\Http\Requests\Customer\UploadContainerFileRequest;
use App\Jobs\BuildContainerArchiveJob;
use App\Jobs\ExtractContainerArchiveJob;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerArchiveCommands;
use App\Services\Provisioning\ContainerFileOperationProgress;
use App\Services\Provisioning\ContainerFileService;
use App\Services\Provisioning\ContainerFileServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContainerFileController extends Controller
{
    public function __construct(
        private ContainerFileServiceFactory $factory,
        private ContainerFileOperationProgress $operations,
    ) {}

    public function index(Service $service, Request $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $path = $request->query('path', '/');

        try {
            $result = $this->files($service)->listDirectory($service, $service->containerDeployment, $path, auth()->user(), $request->ip());

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to list container directory for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to list directory. Please try again or contact support.'], 500);
        }
    }

    public function content(Service $service, Request $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['error' => 'Path is required'], 400);
        }

        try {
            $result = $this->files($service)->readTextFile($service, $service->containerDeployment, $path, auth()->user(), $request->ip());

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to read container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to read file. Please try again or contact support.'], 500);
        }
    }

    public function saveContent(Service $service, SaveContainerFileContentRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $path = (string) $request->input('path');
        $content = (string) $request->input('content');

        try {
            $this->files($service)->writeTextFile($service, $service->containerDeployment, $path, $content, auth()->user(), $request->ip());

            return response()->json(['success' => true, 'path' => $path, 'size' => strlen($content)]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to save container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to save file. Please try again or contact support.'], 500);
        }
    }

    public function download(Service $service, Request $request): StreamedResponse
    {
        $this->authorize('manageFiles', $service);
        if ($service->product?->type !== 'container_hosting') {
            abort(400, 'Service is not an application hosting service');
        }
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            abort(400, 'Application not deployed yet');
        }

        $path = $request->query('path');
        if (! $path) {
            abort(400, 'Path is required');
        }

        try {
            $content = $this->files($service)->download($service, $deployment, $path, auth()->user(), $request->ip());
            $filename = basename($path);

            return response()->streamDownload(function () use ($content) {
                echo $content;
            }, $filename);
        } catch (\InvalidArgumentException $e) {
            abort(403, 'Invalid path: '.$e->getMessage());
        } catch (\Exception $e) {
            \Log::error("Failed to download container file for service {$service->id}: ".$e->getMessage());
            abort(500, 'Failed to download file');
        }
    }

    /**
     * Upload one or more files into a directory, streaming each from the
     * request's temp file. Archives can be extracted right after upload.
     */
    public function upload(Service $service, UploadContainerFileRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $deployment = $service->containerDeployment;
        $path = (string) $request->input('path');
        $files = $this->files($service);
        $results = [];
        $failures = 0;

        try {
            if ($request->isLegacySingleFile()) {
                // Old shape: path is the full target file path.
                $dir = dirname($path);
                $dir = $dir === '.' ? '/' : $dir;
                $file = $request->uploadedFiles()[0];
                $files->mkdir($service, $deployment, $dir, auth()->user(), $request->ip());
                $files->upload($service, $deployment, $path, $file, auth()->user(), $request->ip());

                return response()->json([
                    'success' => true,
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'files' => [['name' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize(), 'status' => 'uploaded']],
                ]);
            }

            $files->mkdir($service, $deployment, $path, auth()->user(), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to prepare upload for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to upload file. Please try again or contact support.'], 500);
        }

        foreach ($request->uploadedFiles() as $file) {
            $name = $file->getClientOriginalName();
            try {
                $relPath = $files->uploadStreamed($service, $deployment, $path, $file, auth()->user(), $request->ip());
                $row = ['name' => $name, 'path' => $relPath, 'size' => $file->getSize(), 'status' => 'uploaded'];

                if ($request->shouldExtract() && ContainerArchiveCommands::kind($name) !== null) {
                    $operation = $this->startExtract($service, $deployment, $relPath, $path, true, $request);
                    $row['status'] = 'extracting';
                    $row['operation'] = $operation;
                }
                $results[] = $row;
            } catch (\InvalidArgumentException $e) {
                $failures++;
                $results[] = ['name' => $name, 'status' => 'failed', 'error' => $e->getMessage()];
            } catch (\Exception $e) {
                $failures++;
                \Log::error("Failed to upload container file for service {$service->id}: ".$e->getMessage());
                $results[] = ['name' => $name, 'status' => 'failed', 'error' => 'Upload failed. Please try again or contact support.'];
            }
        }

        $ok = $failures < count($results);

        return response()->json([
            'success' => $ok,
            'files' => $results,
            'error' => $ok ? null : ($results[0]['error'] ?? 'Upload failed.'),
        ], $ok ? 200 : 422);
    }

    public function delete(Service $service, DeleteContainerPathRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        try {
            $this->files($service)->delete($service, $service->containerDeployment, (string) $request->input('path'), auth()->user(), $request->ip());

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to delete container path for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to delete path. Please try again or contact support.'], 500);
        }
    }

    /**
     * Delete a selection in one SSH session.
     */
    public function batchDelete(Service $service, BatchContainerPathsRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        try {
            $result = $this->files($service)->batchDelete($service, $service->containerDeployment, $request->paths(), auth()->user(), $request->ip());

            return response()->json([
                'success' => $result['failed'] === [],
                'deleted' => $result['deleted'],
                'failed' => $result['failed'],
                'error' => $result['failed'] === [] ? null : count($result['failed']).' item(s) could not be deleted.',
            ], $result['deleted'] === [] && $result['failed'] !== [] ? 422 : 200);
        } catch (\Exception $e) {
            \Log::error("Failed to batch delete for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to delete the selection. Please try again or contact support.'], 500);
        }
    }

    /**
     * Move or copy a selection into another folder.
     */
    public function move(Service $service, MoveContainerPathsRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        try {
            $result = $this->files($service)->moveOrCopy(
                $service,
                $service->containerDeployment,
                $request->paths(),
                $request->destination(),
                $request->isCopy(),
                auth()->user(),
                $request->ip(),
            );

            $problems = count($result['conflicts']) + count($result['failed']);
            $verb = $request->isCopy() ? 'copied' : 'moved';
            $message = sprintf('%d item(s) %s to %s.', count($result['done']), $verb, $result['destination']);
            if ($result['conflicts'] !== []) {
                $message .= ' Already exist at the destination and were skipped: '.implode(', ', array_map('basename', array_slice($result['conflicts'], 0, 5)))
                    .(count($result['conflicts']) > 5 ? '…' : '').'.';
            }

            return response()->json([
                'success' => $problems === 0,
                'message' => $message,
                'done' => $result['done'],
                'conflicts' => $result['conflicts'],
                'failed' => $result['failed'],
                'error' => $problems === 0 ? null : $message,
            ], $result['done'] === [] && $problems > 0 ? 422 : 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to move container paths for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to move the selection. Please try again or contact support.'], 500);
        }
    }

    /**
     * Start extracting an archive; returns an operation token to poll.
     */
    public function extract(Service $service, ExtractContainerArchiveRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        if (ContainerArchiveCommands::kind($request->archivePath()) === null) {
            return response()->json(['error' => 'Only .zip, .tar, .tar.gz and .tgz archives can be extracted.'], 422);
        }

        $operation = $this->startExtract(
            $service,
            $service->containerDeployment,
            $request->archivePath(),
            $request->destination(),
            $request->deleteArchive(),
            $request,
        );

        return response()->json(['success' => true, 'operation' => $operation], 202);
    }

    /**
     * Start packing a selection into a zip; returns an operation token to poll.
     */
    public function archive(Service $service, BatchContainerPathsRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $deployment = $service->containerDeployment;
        $operation = $this->operations->start($service, $deployment, ContainerFileOperationProgress::TYPE_ARCHIVE, [
            'paths' => $request->paths(),
        ]);
        BuildContainerArchiveJob::dispatch(
            $operation['token'],
            (int) $service->id,
            (int) $deployment->id,
            (int) auth()->id(),
            (string) $request->ip(),
            $request->paths(),
        );

        return response()->json(['success' => true, 'operation' => $this->operations->find($operation['token'], $service) ?? $operation], 202);
    }

    /**
     * Poll an operation started by extract or archive.
     */
    public function operation(Service $service, string $token): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        $state = $this->operations->find($token, $service);
        if ($state === null) {
            return response()->json(['error' => 'Operation not found or expired.'], 404);
        }

        return response()->json($state);
    }

    /**
     * Serve a built zip once, then delete it on the node and locally.
     */
    public function downloadArchive(Service $service, string $token): BinaryFileResponse|JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $state = $this->operations->find($token, $service);
        if ($state === null || ($state['status'] ?? '') !== 'completed' || ($state['type'] ?? '') !== ContainerFileOperationProgress::TYPE_ARCHIVE) {
            return response()->json(['error' => 'Archive is not ready.'], 404);
        }

        $remote = (string) ($state['result']['remote_path'] ?? '');
        $name = (string) ($state['result']['name'] ?? 'files.zip');
        if ($remote === '') {
            return response()->json(['error' => 'Archive is no longer available.'], 410);
        }

        try {
            $local = $this->files($service)->fetchArchiveToLocal($service->containerDeployment, $remote);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch built archive for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'The archive could not be fetched from the node. Build it again.'], 500);
        }

        $this->operations->markDownloaded($token);

        return response()->download($local, $name)->deleteFileAfterSend(true);
    }

    public function mkdir(Service $service, CreateContainerDirectoryRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        try {
            $this->files($service)->mkdir($service, $service->containerDeployment, (string) $request->input('path'), auth()->user(), $request->ip());

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to create directory for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to create directory. Please try again or contact support.'], 500);
        }
    }

    public function createFile(Service $service, CreateContainerFileRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        $path = (string) $request->input('path');

        try {
            $this->files($service)->createEmptyFile($service, $service->containerDeployment, $path, auth()->user(), $request->ip());

            return response()->json(['success' => true, 'path' => $path]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to create container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to create file. Please try again or contact support.'], 500);
        }
    }

    public function rename(Service $service, RenameContainerPathRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);
        if ($error = $this->deploymentError($service)) {
            return $error;
        }

        try {
            $result = $this->files($service)->rename(
                $service,
                $service->containerDeployment,
                (string) $request->input('path'),
                (string) $request->input('name'),
                auth()->user(),
                $request->ip(),
            );

            return response()->json(['success' => true, 'path' => $result['path']]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to rename container path for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to rename path. Please try again or contact support.'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function startExtract(Service $service, ContainerDeployment $deployment, string $archivePath, string $destination, bool $deleteArchive, Request $request): array
    {
        $operation = $this->operations->start($service, $deployment, ContainerFileOperationProgress::TYPE_EXTRACT, [
            'archive' => $archivePath,
            'destination' => $destination,
        ]);

        ExtractContainerArchiveJob::dispatch(
            $operation['token'],
            (int) $service->id,
            (int) $deployment->id,
            (int) auth()->id(),
            (string) $request->ip(),
            $archivePath,
            $destination,
            $deleteArchive,
        );

        // On a sync queue the job has already finished; return its final state.
        return $this->operations->find($operation['token'], $service) ?? $operation;
    }

    private function files(Service $service): ContainerFileService
    {
        return $this->factory->make($service->containerDeployment);
    }

    private function deploymentError(Service $service): ?JsonResponse
    {
        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }
        if (! $service->containerDeployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        return null;
    }
}
