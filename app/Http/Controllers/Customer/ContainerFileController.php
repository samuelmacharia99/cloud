<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateContainerDirectoryRequest;
use App\Http\Requests\Customer\CreateContainerFileRequest;
use App\Http\Requests\Customer\DeleteContainerPathRequest;
use App\Http\Requests\Customer\RenameContainerPathRequest;
use App\Http\Requests\Customer\SaveContainerFileContentRequest;
use App\Http\Requests\Customer\UploadContainerFileRequest;
use App\Models\Service;
use App\Services\Provisioning\ContainerFileService;
use App\Services\SSH\SSHService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContainerFileController extends Controller
{
    public function index(Service $service, Request $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->query('path', '/');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $result = $fileService->listDirectory(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to list container directory for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to list directory: '.$e->getMessage()], 500);
        }
    }

    public function content(Service $service, Request $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['error' => 'Path is required'], 400);
        }

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $result = $fileService->readTextFile(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to read container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to read file: '.$e->getMessage()], 500);
        }
    }

    public function saveContent(Service $service, SaveContainerFileContentRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = (string) $request->input('path');
        $content = (string) $request->input('content');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $fileService->writeTextFile(
                $service,
                $deployment,
                $path,
                $content,
                auth()->user(),
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'path' => $path,
                'size' => strlen($content),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to save container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to save file: '.$e->getMessage()], 500);
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
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $content = $fileService->download(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            $filename = basename($path);

            return response()->streamDownload(
                function () use ($content) {
                    echo $content;
                },
                $filename
            );
        } catch (\InvalidArgumentException $e) {
            abort(403, 'Invalid path: '.$e->getMessage());
        } catch (\Exception $e) {
            \Log::error("Failed to download container file for service {$service->id}: ".$e->getMessage());
            abort(500, 'Failed to download file');
        }
    }

    public function upload(Service $service, UploadContainerFileRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->input('path');
        $file = $request->file('file');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            // Create directory if needed
            $dir = dirname($path);
            if ($dir !== '/') {
                $fileService->mkdir($service, $deployment, $dir, auth()->user(), $request->ip());
            }

            $fileService->upload(
                $service,
                $deployment,
                $path,
                $file,
                auth()->user(),
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to upload container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to upload file: '.$e->getMessage()], 500);
        }
    }

    public function delete(Service $service, DeleteContainerPathRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->input('path');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $fileService->delete(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to delete container path for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to delete: '.$e->getMessage()], 500);
        }
    }

    public function mkdir(Service $service, CreateContainerDirectoryRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->input('path');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $fileService->mkdir(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid path: '.$e->getMessage()], 403);
        } catch (\Exception $e) {
            \Log::error("Failed to create directory for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to create directory: '.$e->getMessage()], 500);
        }
    }

    public function createFile(Service $service, CreateContainerFileRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->input('path');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $fileService->createEmptyFile(
                $service,
                $deployment,
                $path,
                auth()->user(),
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'path' => $path,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to create container file for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to create file: '.$e->getMessage()], 500);
        }
    }

    public function rename(Service $service, RenameContainerPathRequest $request): JsonResponse
    {
        $this->authorize('manageFiles', $service);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Service is not an application hosting service'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Application not deployed yet'], 400);
        }

        $path = $request->input('path');
        $name = $request->input('name');

        try {
            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $result = $fileService->rename(
                $service,
                $deployment,
                $path,
                $name,
                auth()->user(),
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'path' => $result['path'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error("Failed to rename container path for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to rename: '.$e->getMessage()], 500);
        }
    }
}
