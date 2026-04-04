<?php $__env->startSection('title', 'Ticket #' . $ticket->id); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div>
        <a href="<?php echo e(route('tickets.index')); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Back to tickets</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Thread -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Ticket Header -->
            <div class="bg-white rounded-2xl border border-slate-200 p-8">
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900"><?php echo e($ticket->title); ?></h1>
                        <p class="text-slate-600 mt-1">Ticket #<?php echo e($ticket->id); ?></p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-sm font-medium <?php echo e($ticket->status === 'closed' ? 'bg-slate-100 text-slate-700' : 'bg-blue-100 text-blue-700'); ?>">
                        <?php echo e(ucfirst($ticket->status)); ?>

                    </span>
                </div>

                <p class="text-slate-700 whitespace-pre-wrap"><?php echo e($ticket->description); ?></p>

                <div class="mt-6 pt-6 border-t border-slate-200 flex items-center justify-between text-sm text-slate-600">
                    <span><?php echo e($ticket->user->name); ?> • <?php echo e($ticket->created_at->format('M d, Y \a\t h:i A')); ?></span>
                    <span class="px-2 py-1 rounded bg-slate-100"><?php echo e(ucfirst($ticket->priority)); ?> Priority</span>
                </div>
            </div>

            <!-- Replies -->
            <div class="space-y-4">
                <?php $__currentLoopData = $ticket->replies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reply): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="bg-white rounded-2xl border border-slate-200 p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-bold">
                                <?php echo e(strtoupper(substr($reply->user->name, 0, 1))); ?>

                            </div>
                            <div>
                                <p class="font-semibold text-slate-900"><?php echo e($reply->user->name); ?></p>
                                <p class="text-xs text-slate-600">
                                    <?php echo e($reply->created_at->format('M d, Y \a\t h:i A')); ?>

                                    <?php if($reply->is_staff_reply): ?>
                                        <span class="ml-2 px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-xs font-medium">Staff</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <p class="text-slate-700 whitespace-pre-wrap"><?php echo e($reply->message); ?></p>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>

            <!-- Reply Form -->
            <?php if($ticket->status !== 'closed'): ?>
                <form action="<?php echo e(route('tickets.reply', $ticket)); ?>" method="POST" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-900 mb-2">Your Reply</label>
                        <textarea name="message" rows="4" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Type your reply..."></textarea>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors">
                        Send Reply
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Ticket Info -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Ticket Info</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs text-slate-600 uppercase font-semibold mb-1">Status</p>
                        <p class="text-slate-900"><?php echo e(ucfirst($ticket->status)); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 uppercase font-semibold mb-1">Priority</p>
                        <p class="text-slate-900"><?php echo e(ucfirst($ticket->priority)); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 uppercase font-semibold mb-1">Created</p>
                        <p class="text-slate-900"><?php echo e($ticket->created_at->format('M d, Y')); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 uppercase font-semibold mb-1">Replies</p>
                        <p class="text-slate-900"><?php echo e($ticket->replies->count()); ?></p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <?php if($ticket->status !== 'closed'): ?>
                <form action="<?php echo e(route('tickets.close', $ticket)); ?>" method="POST">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="w-full px-4 py-2 rounded-lg bg-slate-600 text-white font-medium hover:bg-slate-700 transition-colors">
                        Close Ticket
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/tickets/show.blade.php ENDPATH**/ ?>