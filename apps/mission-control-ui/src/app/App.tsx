import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppShell } from './AppShell';
import { RequireAuth } from './RequireAuth';
import { LoginPage } from '@/pages/LoginPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { ProjectsPage } from '@/pages/ProjectsPage';
import { MissionsPage } from '@/pages/MissionsPage';
import { MissionDetailsPage } from '@/pages/MissionDetailsPage';
import { NewMissionPage } from '@/pages/NewMissionPage';
import { TimelinePage } from '@/pages/TimelinePage';
import { ArtifactsPage } from '@/pages/ArtifactsPage';
import { ValidationPage } from '@/pages/ValidationPage';
import { ApprovalsPage } from '@/pages/ApprovalsPage';
import { SettingsPage } from '@/pages/SettingsPage';
import { WorkspacesPage } from '@/pages/WorkspacesPage';
import { PatchesPage } from '@/pages/PatchesPage';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 2000,
      refetchOnWindowFocus: false,
    },
  },
});

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<AppShell />}>
              <Route index element={<DashboardPage />} />
              <Route path="projects" element={<ProjectsPage />} />
              <Route path="missions" element={<MissionsPage />} />
              <Route path="missions/new" element={<NewMissionPage />} />
              <Route path="missions/:missionId" element={<MissionDetailsPage />} />
              <Route path="timeline" element={<TimelinePage />} />
              <Route path="artifacts" element={<ArtifactsPage />} />
              <Route path="workspaces" element={<WorkspacesPage />} />
              <Route path="patches" element={<PatchesPage />} />
              <Route path="validation" element={<ValidationPage />} />
              <Route path="approvals" element={<ApprovalsPage />} />
              <Route path="settings" element={<SettingsPage />} />
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
