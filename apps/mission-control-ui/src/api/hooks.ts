import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiUser, setCsrfToken } from './client';

export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      const data = await api<{ user: ApiUser; csrfToken: string }>('/auth/me');
      setCsrfToken(data.csrfToken);
      return data;
    },
    retry: false,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { username: string; password: string }) =>
      api<{ user: ApiUser; csrfToken: string }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify(body),
      }),
    onSuccess: (data) => {
      setCsrfToken(data.csrfToken);
      qc.setQueryData(['me'], data);
    },
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api<{ ok: boolean }>('/auth/logout', { method: 'POST' }),
    onSuccess: () => {
      setCsrfToken('');
      qc.clear();
    },
  });
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<Record<string, unknown>>('/dashboard'),
    refetchInterval: 5000,
  });
}

export function useProjects() {
  return useQuery({
    queryKey: ['projects'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/projects'),
    refetchInterval: 8000,
  });
}

export function useMissions() {
  return useQuery({
    queryKey: ['missions'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/missions'),
    refetchInterval: 4000,
  });
}

export function useMission(id: string) {
  return useQuery({
    queryKey: ['mission', id],
    queryFn: () => api<Record<string, unknown>>(`/missions/${id}`),
    enabled: Boolean(id),
    refetchInterval: 3000,
  });
}

export function useTimeline(id: string) {
  return useQuery({
    queryKey: ['timeline', id],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/missions/${id}/timeline`),
    enabled: Boolean(id),
    refetchInterval: 3000,
  });
}

export function useApprovals() {
  return useQuery({
    queryKey: ['approvals'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/approvals'),
    refetchInterval: 4000,
  });
}

export function useArtifacts(missionId?: string) {
  const q = missionId ? `?missionId=${encodeURIComponent(missionId)}` : '';
  return useQuery({
    queryKey: ['artifacts', missionId ?? 'all'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/artifacts${q}`),
    refetchInterval: 8000,
  });
}

export function useArtifactWorkspace(workspaceId: string) {
  return useQuery({
    queryKey: ['artifact-workspace', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/artifacts/${workspaceId}`),
    enabled: Boolean(workspaceId),
  });
}

export function useValidation() {
  return useQuery({
    queryKey: ['validation'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/validation'),
    refetchInterval: 8000,
  });
}

export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: () => api<Record<string, unknown>>('/settings'),
  });
}

export function useExecutionProviders() {
  return useQuery({
    queryKey: ['execution-providers'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>('/execution/providers'),
    refetchInterval: 10000,
  });
}

export function useMissionExecution(missionId: string) {
  return useQuery({
    queryKey: ['mission-execution', missionId],
    queryFn: () => api<{ session: Record<string, unknown> | null }>(`/missions/${missionId}/execution`),
    enabled: Boolean(missionId),
    refetchInterval: 3000,
  });
}

export function useExecutionEvents(sessionId: string) {
  return useQuery({
    queryKey: ['execution-events', sessionId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/execution/sessions/${sessionId}/events`),
    enabled: Boolean(sessionId),
    refetchInterval: 2500,
  });
}

export function useExecutionPrompt(sessionId: string) {
  return useQuery({
    queryKey: ['execution-prompt', sessionId],
    queryFn: () => api<Record<string, unknown>>(`/execution/sessions/${sessionId}/prompt`),
    enabled: Boolean(sessionId),
  });
}

export function useExecutionMetrics(sessionId: string) {
  return useQuery({
    queryKey: ['execution-metrics', sessionId],
    queryFn: () => api<Record<string, unknown>>(`/execution/sessions/${sessionId}/metrics`),
    enabled: Boolean(sessionId),
    refetchInterval: 3000,
  });
}

export function useExecutionSettings() {
  return useQuery({
    queryKey: ['execution-settings'],
    queryFn: () =>
      api<{ settings: Record<string, unknown>; providers: Array<Record<string, unknown>> }>(
        '/settings/execution',
      ),
  });
}

export function useWorkspaces(missionId?: string) {
  const q = missionId ? `?missionId=${encodeURIComponent(missionId)}` : '';
  return useQuery({
    queryKey: ['workspaces', missionId ?? 'all'],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/workspaces${q}`),
    refetchInterval: 5000,
  });
}

export function useWorkspace(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}`),
    enabled: Boolean(workspaceId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceTimeline(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-timeline', workspaceId],
    queryFn: () => api<{ items: Array<Record<string, unknown>> }>(`/workspaces/${workspaceId}/timeline`),
    enabled: Boolean(workspaceId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceMounts(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-mounts', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}/mounts`),
    enabled: Boolean(workspaceId),
  });
}

export function useWorkspaceSize(workspaceId: string) {
  return useQuery({
    queryKey: ['workspace-size', workspaceId],
    queryFn: () => api<Record<string, unknown>>(`/workspaces/${workspaceId}/size`),
    enabled: Boolean(workspaceId),
    refetchInterval: 5000,
  });
}

export function useMissionWorkspace(missionId: string) {
  return useQuery({
    queryKey: ['mission-workspace', missionId],
    queryFn: () => api<{ workspace: Record<string, unknown> | null }>(`/missions/${missionId}/workspace`),
    enabled: Boolean(missionId),
    refetchInterval: 4000,
  });
}

export function useWorkspaceSettings() {
  return useQuery({
    queryKey: ['workspace-settings'],
    queryFn: () => api<{ settings: Record<string, unknown> }>('/settings/workspaces'),
  });
}

export function useApprovalDecision() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: {
      missionId: string;
      kind: string;
      decision: 'approved' | 'rejected';
      rationale?: string;
      runId?: string;
      gateId?: string;
    }) => {
      if (input.kind === 'inspection') {
        return api(`/approvals/${input.missionId}/inspection`, {
          method: 'POST',
          body: JSON.stringify({
            decision: input.decision,
            rationale: input.rationale ?? '',
          }),
        });
      }
      if (input.kind === 'merge' || input.kind === 'commit') {
        return api(`/approvals/${input.missionId}/commit`, {
          method: 'POST',
          body: JSON.stringify({
            decision: input.decision,
            rationale: input.rationale ?? '',
          }),
        });
      }
      return api(`/approvals/${input.missionId}/gates/${input.gateId}`, {
        method: 'POST',
        body: JSON.stringify({
          decision: input.decision,
          runId: input.runId,
        }),
      });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['approvals'] });
      void qc.invalidateQueries({ queryKey: ['missions'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
