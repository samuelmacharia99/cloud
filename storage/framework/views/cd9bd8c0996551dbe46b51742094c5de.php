<?php $__env->startSection('title', 'Support Tickets'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Support Tickets</h1>
            <p class="text-slate-600 mt-1">Track your support requests and get help.</p>
        </div>
        <a href="<?php echo e(route('tickets.create')); ?>" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
            + Create Ticket
        </a>
    </div>

    <!-- Tickets Table -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Ticket</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Priority</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Created</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php $__empty_1 = true; $__currentLoopData = $tickets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900"><?php echo e($ticket->title); ?></p>
                                <p class="text-xs text-slate-500">#<?php echo e($ticket->id); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?php echo e($ticket->priority === 'urgent' || $ticket->priority === 'high' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700'); ?>">
                                    <?php echo e(ucfirst($ticket->priority)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?php echo e($ticket->status === 'closed' ? 'bg-slate-100 text-slate-700' : 'bg-blue-100 text-blue-700'); ?>">
                                    <?php echo e(ucfirst($ticket->status)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600"><?php echo e($ticket->created_at->format('M d, Y')); ?></p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="<?php echo e(route('tickets.show', $ticket)); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">View</a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500">No tickets found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if($tickets->hasPages()): ?>
        <div class="flex items-center justify-center">
            <?php echo e($tickets->links()); ?>

        </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/tickets/index.blade.php ENDPATH**/ ?>