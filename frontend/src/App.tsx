import { Spinner } from '@heroui/spinner';
import { lazy, Suspense } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';

import { ConfirmCardHost } from '@/components/confirm_card';
import PageBackground from '@/components/page_background';
import PageLoadingBar from '@/components/page_loading_bar';
import Toaster from '@/components/toaster';
import { AuthProvider, useSignAuth } from '@/contexts/auth';
import { SiteProvider } from '@/contexts/site';
import SignLayout from '@/layouts/sign_layout';

const HomePage = lazy(() => import('@/pages/home'));
const LoginPage = lazy(() => import('@/pages/web_login'));
const RegisterPage = lazy(() => import('@/pages/web_register'));
const QqRegisterPage = lazy(() => import('@/pages/qq_register'));
const ForgotPasswordPage = lazy(() => import('@/pages/forgot_password'));
const InstallPage = lazy(() => import('@/pages/install'));
const DashboardPage = lazy(() => import('@/pages/sign/dashboard'));
const AccountsPage = lazy(() => import('@/pages/sign/accounts'));
const TasksPage = lazy(() => import('@/pages/sign/tasks'));
const TaskDetailPage = lazy(() => import('@/pages/sign/task_detail'));
const PluginsPage = lazy(() => import('@/pages/sign/plugins'));
const ProfilePage = lazy(() => import('@/pages/sign/profile'));
const AssistantPage = lazy(() => import('@/pages/sign/assistant'));
const AdminLayoutPage = lazy(() => import('@/pages/sign/admin/layout'));
const AdminOverviewPage = lazy(() => import('@/pages/sign/admin/overview'));
const AdminUsersPage = lazy(() => import('@/pages/sign/admin/users'));
const AdminAccountsPage = lazy(() => import('@/pages/sign/admin/accounts'));
const AdminTasksPage = lazy(() => import('@/pages/sign/admin/tasks'));
const AdminAuditPage = lazy(() => import('@/pages/sign/admin/audit'));
const AdminSettingsPage = lazy(() => import('@/pages/sign/admin/settings'));
const AdminVersionPage = lazy(() => import('@/pages/sign/admin/version'));
const AdminMailPage = lazy(() => import('@/pages/sign/admin/mail'));
const NotFoundPage = lazy(() => import('@/pages/not_found'));

function LoadingScreen ({ label = '正在加载天方云签' }: { label?: string }) {
  return <div className='flex min-h-screen items-center justify-center bg-background'><Spinner size='lg' label={label} /></div>;
}

function ProtectedRoute () {
  const { loading, isAuthenticated } = useSignAuth();
  const location = useLocation();
  if (loading) return <LoadingScreen />;
  if (!isAuthenticated) return <Navigate to='/login' replace state={{ from: location.pathname }} />;
  return <Outlet />;
}

function AdminRoute () {
  const { user } = useSignAuth();
  return user?.role === 'admin' ? <Outlet /> : <Navigate to='/TFYT' replace />;
}

function LegacyConsoleRedirect () {
  const location = useLocation();
  const legacyPath = location.pathname.startsWith('/console')
    ? location.pathname.slice('/console'.length)
    : location.pathname;
  return <Navigate to={`/TFYT${legacyPath}${location.search}`} replace />;
}

export default function App () {
  return (
    <SiteProvider>
    <AuthProvider>
      <PageBackground />
      <PageLoadingBar />
      <Toaster />
      <ConfirmCardHost />
      <Suspense fallback={<LoadingScreen />}>
        <Routes>
            <Route path='/' element={<HomePage />} />
            <Route path='/install' element={<InstallPage />} />
            <Route path='/login' element={<LoginPage />} />
            <Route path='/register' element={<RegisterPage />} />
            <Route path='/qq-register' element={<QqRegisterPage />} />
            <Route path='/forgot-password' element={<ForgotPasswordPage />} />
            <Route path='/web_login' element={<Navigate to='/login' replace />} />
            <Route element={<ProtectedRoute />}>
              <Route path='/TFYT' element={<SignLayout />}>
                <Route index element={<DashboardPage />} />
                <Route path='accounts' element={<AccountsPage />} />
                <Route path='tasks' element={<TasksPage />} />
                <Route path='tasks/:taskNo' element={<TaskDetailPage />} />
                <Route path='plugins' element={<Navigate to='/TFYT/accounts' replace />} />
                <Route path='profile' element={<ProfilePage />} />
                <Route path='assistant' element={<AssistantPage />} />
                <Route path='qq_login' element={<Navigate to='/TFYT/accounts' replace />} />
                <Route element={<AdminRoute />}>
                  <Route path='admin' element={<AdminLayoutPage />}>
                    <Route index element={<AdminOverviewPage />} />
                    <Route path='users' element={<AdminUsersPage />} />
                    <Route path='accounts' element={<AdminAccountsPage />} />
                    <Route path='tasks' element={<AdminTasksPage />} />
                    <Route path='tasks/:taskNo' element={<TaskDetailPage admin />} />
                    <Route path='audit' element={<AdminAuditPage />} />
                    <Route path='settings' element={<AdminSettingsPage />} />
                    <Route path='version' element={<AdminVersionPage />} />
                    <Route path='mail' element={<AdminMailPage />} />
                    <Route path='plugins' element={<PluginsPage />} />
                    <Route path='*' element={<NotFoundPage />} />
                  </Route>
                </Route>
                <Route path='*' element={<NotFoundPage />} />
              </Route>
            </Route>
            <Route path='/console/*' element={<LegacyConsoleRedirect />} />
            <Route path='/accounts' element={<LegacyConsoleRedirect />} />
            <Route path='/tasks/*' element={<LegacyConsoleRedirect />} />
            <Route path='/plugins' element={<LegacyConsoleRedirect />} />
            <Route path='/profile' element={<LegacyConsoleRedirect />} />
            <Route path='/assistant' element={<LegacyConsoleRedirect />} />
            <Route path='/qq_login' element={<LegacyConsoleRedirect />} />
            <Route path='/admin/*' element={<LegacyConsoleRedirect />} />
            <Route path='*' element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </AuthProvider>
    </SiteProvider>
  );
}
