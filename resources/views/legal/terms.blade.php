@extends('layouts.guest')

@section('title', 'Terms of Service')

@section('content')
<div class="px-6 lg:px-12 py-12">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-4xl font-bold mb-2">Terms of Service</h1>
        <p class="text-slate-600 dark:text-slate-400 mb-8">Last updated: May 2026</p>

        <div class="prose dark:prose-invert max-w-none space-y-6">
            <section>
                <h2 class="text-2xl font-bold mb-4">1. Acceptance of Terms</h2>
                <p>By accessing and using Talksasa Cloud (the "Service"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by the above, please do not use this service.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">2. Use License</h2>
                <p>Permission is granted to temporarily download one copy of the materials (information or software) on Talksasa Cloud for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
                <ul class="list-disc list-inside space-y-2 ml-4">
                    <li>Modifying or copying the materials</li>
                    <li>Using the materials for any commercial purpose or for any public display</li>
                    <li>Attempting to decompile, reverse engineer, disassemble, or otherwise attempt to discover any source code</li>
                    <li>Transferring the materials to another person or "mirroring" the materials on any other server</li>
                    <li>Attempting to gain unauthorized access to any portion or feature of the Service</li>
                    <li>Removing any copyright, trademark, or other proprietary notices</li>
                    <li>Harassing, threatening, or intimidating other users</li>
                </ul>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">3. Disclaimer</h2>
                <p>The materials on Talksasa Cloud are provided on an 'as is' basis. Talksasa Cloud makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">4. Limitations</h2>
                <p>In no event shall Talksasa Cloud or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on Talksasa Cloud, even if Talksasa Cloud or an authorized representative has been notified orally or in writing of the possibility of such damage.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">5. Accuracy of Materials</h2>
                <p>The materials appearing on Talksasa Cloud could include technical, typographical, or photographic errors. Talksasa Cloud does not warrant that any of the materials on the Service are accurate, complete, or current. Talksasa Cloud may make changes to the materials contained on the Service at any time without notice.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">6. Links</h2>
                <p>Talksasa Cloud has not reviewed all of the sites linked to its website and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by Talksasa Cloud of the site. Use of any such linked website is at the user's own risk.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">7. Modifications</h2>
                <p>Talksasa Cloud may revise these terms of service for its website at any time without notice. By using this website, you are agreeing to be bound by the then current version of these terms of service.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">8. Governing Law</h2>
                <p>These terms and conditions are governed by and construed in accordance with the laws of Kenya, and you irrevocably submit to the exclusive jurisdiction of the courts in that location.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">9. Billing and Payment</h2>
                <p>You agree to pay all fees and charges that you incur through your use of the Service. You authorize us to charge your selected payment method for all applicable fees and charges. Billing disputes must be reported within 30 days of the charge.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">10. Service Suspension</h2>
                <p>We reserve the right to suspend or terminate your account and access to the Service at any time if you violate these Terms of Service, engage in fraudulent activity, or fail to pay outstanding charges.</p>
            </section>

            <section>
                <h2 class="text-2xl font-bold mb-4">11. Contact Information</h2>
                <p>If you have any questions about these Terms of Service, please contact us at:</p>
                <div class="mt-4 p-4 bg-slate-100 dark:bg-slate-800 rounded-lg">
                    <p><strong>Email:</strong> <a href="mailto:support@talksasa.cloud" class="text-blue-600 dark:text-blue-400 hover:underline">support@talksasa.cloud</a></p>
                    <p><strong>Company:</strong> Talksasa Cloud Limited</p>
                    <p><strong>Country:</strong> Kenya</p>
                </div>
            </section>
        </div>

        <div class="mt-12 p-6 bg-blue-50 dark:bg-blue-950/30 rounded-lg border border-blue-200 dark:border-blue-800">
            <p class="text-sm">
                <strong>Note:</strong> By creating an account and using Talksasa Cloud, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service and our Privacy Policy.
            </p>
        </div>

        <div class="mt-8">
            <a href="{{ route('register') }}" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                Back to Registration
            </a>
        </div>
    </div>
</div>
@endsection
